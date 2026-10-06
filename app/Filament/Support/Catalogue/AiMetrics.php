<?php

namespace App\Filament\Support\Catalogue;

use App\Enums\AiJobStatus;
use App\Models\AiJob;
use App\Models\AiUsage;
use App\Models\AiWorkflow;
use App\Models\Feedback;
use App\Models\PromptVersion;
use App\Models\QualityReview;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregates for the AI control centre. Every query is a single
 * aggregate (no per-row queries), bounded to a time window.
 */
final class AiMetrics
{
    public function __construct(public readonly CarbonInterface $since) {}

    public static function lastDays(int $days = 30): self
    {
        return new self(now()->subDays($days)->startOfDay());
    }

    /** @return array<string, int> status value => number of jobs started in the window */
    public function jobsByStatus(): array
    {
        return AiJob::query()
            ->where('created_at', '>=', $this->since)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** Failed jobs as a share of jobs that reached an end state (completed, failed, manual review). */
    public function failureRate(?array $byStatus = null): ?float
    {
        $byStatus ??= $this->jobsByStatus();
        $failed = $byStatus[AiJobStatus::Failed->value] ?? 0;
        $ended = $failed + ($byStatus[AiJobStatus::Completed->value] ?? 0) + ($byStatus[AiJobStatus::ManualReview->value] ?? 0);

        return $ended > 0 ? round($failed / $ended * 100, 1) : null;
    }

    /** Average minutes from start to finish of completed jobs. */
    public function averageGenerationMinutes(): ?float
    {
        $seconds = AiJob::query()
            ->where('created_at', '>=', $this->since)
            ->where('status', AiJobStatus::Completed->value)
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->avg(DB::raw('TIMESTAMPDIFF(SECOND, started_at, finished_at)'));

        return $seconds !== null ? round((float) $seconds / 60, 1) : null;
    }

    /** @return array{orders:int, total:float, average:?float} estimated model + search cost per order (USD) */
    public function costPerOrder(): array
    {
        $perOrder = AiUsage::query()
            ->where('created_at', '>=', $this->since)
            ->whereNotNull('order_id')
            ->selectRaw('order_id, SUM(estimated_cost_usd) as cost')
            ->groupBy('order_id');

        $row = DB::query()->fromSub($perOrder, 'per_order')
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(cost), 0) as total, AVG(cost) as average')
            ->first();

        return [
            'orders' => (int) ($row->orders ?? 0),
            'total' => round((float) ($row->total ?? 0), 2),
            'average' => isset($row->average) ? round((float) $row->average, 4) : null,
        ];
    }

    /** @return array{input:int, cached:int, output:int, reasoning:int, search_calls:int, calls:int, cost:float} */
    public function usage(?CarbonInterface $since = null): array
    {
        $row = AiUsage::query()
            ->where('created_at', '>=', $since ?? $this->since)
            ->selectRaw('COUNT(*) as calls, COALESCE(SUM(input_tokens), 0) as input, COALESCE(SUM(cached_input_tokens), 0) as cached,
                COALESCE(SUM(output_tokens), 0) as output, COALESCE(SUM(reasoning_tokens), 0) as reasoning,
                COALESCE(SUM(search_calls), 0) as search_calls, COALESCE(SUM(estimated_cost_usd), 0) as cost')
            ->first();

        return [
            'input' => (int) $row->input,
            'cached' => (int) $row->cached,
            'output' => (int) $row->output,
            'reasoning' => (int) $row->reasoning,
            'search_calls' => (int) $row->search_calls,
            'calls' => (int) $row->calls,
            'cost' => round((float) $row->cost, 2),
        ];
    }

    /** Average overall score of the final quality review of each job. */
    public function averageQualityScore(): ?float
    {
        $finalRounds = QualityReview::query()
            ->where('created_at', '>=', $this->since)
            ->selectRaw('MAX(id)')
            ->groupBy('ai_job_id');

        $average = QualityReview::query()->whereIn('id', $finalRounds)->avg('overall_score');

        return $average !== null ? round((float) $average, 2) : null;
    }

    /**
     * Customer ratings grouped by the prompt versions that produced the documents.
     * feedback.prompt_versions is the job's snapshot, {"key": {"id":…, "version":…}}
     * ("version":"builtin" when the built-in prompt was used); a key mapped to a
     * bare id or version number, or a plain list of ids, is accepted as well.
     *
     * @return list<array{prompt_key:string, version:?int, label:?string, status:?string, average:float, count:int}>
     */
    public function ratingsByPromptVersion(): array
    {
        $rows = Feedback::query()
            ->where('created_at', '>=', $this->since)
            ->whereNotNull('prompt_versions')
            ->get(['rating', 'prompt_versions']);

        $references = [];
        foreach ($rows as $feedback) {
            foreach ($this->promptReferences((array) $feedback->prompt_versions) as $reference) {
                $references[] = $reference + ['rating' => (int) $feedback->rating];
            }
        }

        if ($references === []) {
            return [];
        }

        $ids = array_filter(array_column($references, 'id'));
        $byId = $ids ? PromptVersion::query()->whereIn('id', array_unique($ids))->get()->keyBy('id') : collect();
        $keys = array_unique(array_filter(array_column($references, 'key')));
        $byKeyVersion = $keys
            ? PromptVersion::query()->whereIn('prompt_key', $keys)->get()->keyBy(fn (PromptVersion $v) => $v->prompt_key.'#'.$v->version)
            : collect();

        $groups = [];
        foreach ($references as $reference) {
            $version = null;
            if ($reference['id'] && ($candidate = $byId->get($reference['id'])) && ($reference['key'] === null || $candidate->prompt_key === $reference['key'])) {
                $version = $candidate;
            } elseif ($reference['key'] !== null && $reference['version'] !== null) {
                $version = $byKeyVersion->get($reference['key'].'#'.$reference['version']);
            } elseif ($reference['key'] !== null && $reference['id'] !== null) {
                // A bare number under a key that is not a matching id: read it as a version number.
                $version = $byKeyVersion->get($reference['key'].'#'.$reference['id']);
            }

            $key = $version?->prompt_key ?? $reference['key'] ?? 'unknown';
            $number = $version?->version ?? $reference['version'];
            $group = $key.'#'.($number ?? ($reference['builtin'] ? 'builtin' : '?'));

            $groups[$group] ??= [
                'prompt_key' => $key,
                'version' => $number,
                'label' => $version?->label ?? ($reference['builtin'] ? 'Built-in default prompt' : null),
                'status' => $version?->status,
                'sum' => 0,
                'count' => 0,
            ];
            $groups[$group]['sum'] += $reference['rating'];
            $groups[$group]['count']++;
        }

        $result = array_map(fn (array $group): array => [
            'prompt_key' => $group['prompt_key'],
            'version' => $group['version'],
            'label' => $group['label'],
            'status' => $group['status'],
            'average' => round($group['sum'] / $group['count'], 2),
            'count' => $group['count'],
        ], array_values($groups));

        usort($result, fn (array $a, array $b): int => [$a['prompt_key'], -($a['version'] ?? 0)] <=> [$b['prompt_key'], -($b['version'] ?? 0)]);

        return $result;
    }

    /** @return list<array{workflow:string, version:?int, average:float, count:int}> */
    public function ratingsByWorkflow(): array
    {
        $rows = Feedback::query()
            ->where('created_at', '>=', $this->since)
            ->selectRaw('ai_workflow_id, AVG(rating) as average, COUNT(*) as count')
            ->groupBy('ai_workflow_id')
            ->get();

        $workflows = AiWorkflow::query()->whereIn('id', $rows->pluck('ai_workflow_id')->filter())->get()->keyBy('id');

        return $rows->map(fn ($row): array => [
            'workflow' => $row->ai_workflow_id ? ($workflows->get($row->ai_workflow_id)?->name ?? 'Deleted workflow') : 'Not recorded',
            'version' => $row->ai_workflow_id ? $workflows->get($row->ai_workflow_id)?->version : null,
            'average' => round((float) $row->average, 2),
            'count' => (int) $row->count,
        ])->sortByDesc('count')->values()->all();
    }

    /** @return Collection<int, AiJob> */
    public function recentFailures(int $limit = 10): Collection
    {
        return AiJob::query()
            ->with('order:id,public_id,reference')
            ->where(fn ($query) => $query->whereNotNull('last_error_code')->orWhere('status', AiJobStatus::Failed->value))
            ->latest('updated_at')
            ->limit($limit)
            ->get(['id', 'order_id', 'status', 'current_stage', 'last_error_code', 'last_error_message', 'failure_count', 'updated_at']);
    }

    /**
     * @return list<array{key:?string, id:?int, version:?int, builtin:bool}>
     */
    private function promptReferences(array $data): array
    {
        $references = [];

        foreach ($data as $key => $value) {
            $promptKey = is_string($key) ? $key : null;

            if (is_array($value)) {
                $references[] = [
                    'key' => $promptKey ?? (isset($value['prompt_key']) ? (string) $value['prompt_key'] : null),
                    'id' => isset($value['id']) && is_numeric($value['id']) ? (int) $value['id'] : null,
                    'version' => isset($value['version']) && is_numeric($value['version']) ? (int) $value['version'] : null,
                    'builtin' => ($value['version'] ?? null) === 'builtin',
                ];
            } elseif (is_numeric($value)) {
                $references[] = ['key' => $promptKey, 'id' => (int) $value, 'version' => null, 'builtin' => false];
            }
        }

        return $references;
    }
}
