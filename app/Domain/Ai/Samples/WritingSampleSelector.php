<?php

namespace App\Domain\Ai\Samples;

use App\Domain\Documents\RequirementResolver;
use App\Enums\DocumentKind;
use App\Models\AiJob;
use App\Models\Order;
use App\Models\WritingSample;
use Illuminate\Support\Collection;

/**
 * Chooses the writing samples a job is shown: active samples of the order's
 * document type, most relevant first (field of study › degree level ›
 * destination country), then the administrator's priority. Ties are broken
 * per order, so equally good samples take turns instead of every order
 * learning from the same two documents.
 *
 * The choice is made once per job and stored in ai_jobs.writing_sample_ids,
 * so every stage, retry and the copy check see the same samples.
 * Workflow config: writing_samples.enabled, writing_samples.max_samples.
 */
class WritingSampleSelector
{
    public const MAX_SAMPLES = 5;

    /** Candidates considered per document type (newest first). */
    private const MAX_CANDIDATES = 200;

    /** Characters of each sample a prompt receives. */
    public const PROMPT_CHARS = 9000;

    /** Words shorter than this do not count when matching a field of study. */
    private const MIN_FIELD_WORD = 4;

    /**
     * The job's samples in the order they were chosen (deactivated ones
     * included: the copy check still needs every sample the model saw).
     *
     * @return Collection<int, WritingSample>
     */
    public function forJob(AiJob $job, Order $order, DocumentKind $kind): Collection
    {
        $ids = $job->writing_sample_ids;

        if (! is_array($ids)) {
            $ids = $this->enabled($job) ? $this->choose($order, $kind, $this->limit($job)) : [];
            $job->forceFill(['writing_sample_ids' => $ids])->save();
        }

        if ($ids === []) {
            return collect();
        }

        $samples = WritingSample::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $samples->get($id))->filter()->values();
    }

    /** @return list<int> sample ids, best first */
    public function choose(Order $order, DocumentKind $kind, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $candidates = WritingSample::query()
            ->active()
            ->where('document_kind', $kind->value)
            ->orderByDesc('id')
            ->limit(self::MAX_CANDIDATES)
            ->get(['id', 'degree_level', 'field_of_study', 'country_code', 'priority']);

        $programme = mb_strtolower(trim((string) $order->programme));
        $level = RequirementResolver::degreeLevel($order->degree_level);
        $country = strtoupper((string) $order->country_code);
        $seed = (string) $order->public_id;

        return $candidates
            ->map(fn (WritingSample $sample) => [
                'id' => (int) $sample->id,
                'score' => $this->score($sample, $programme, $level, $country),
                'priority' => (int) $sample->priority,
                'tiebreak' => crc32($seed.':'.$sample->id),
            ])
            ->sort(fn (array $a, array $b) => [$b['score'], $b['priority'], $a['tiebreak']] <=> [$a['score'], $a['priority'], $b['tiebreak']])
            ->take($limit)
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * What a prompt receives: active samples only, without titles (an
     * administrator's title may name the original author).
     *
     * @param  iterable<WritingSample>  $samples
     * @return list<array<string, string>>
     */
    public static function forPrompt(iterable $samples): array
    {
        $payload = [];
        foreach ($samples as $sample) {
            if (! $sample->is_active) {
                continue;
            }

            $payload[] = array_filter([
                'sample' => (string) (count($payload) + 1),
                'document_type' => $sample->document_kind->getLabel(),
                'degree_level' => (string) $sample->degree_level,
                'field_of_study' => (string) $sample->field_of_study,
                'editor_notes' => trim((string) $sample->notes),
                'text' => self::excerpt((string) $sample->content),
            ], fn (string $value) => $value !== '');
        }

        return $payload;
    }

    /** The sample text, cut at a paragraph or sentence end when it is too long. */
    public static function excerpt(string $text): string
    {
        if (mb_strlen($text) <= self::PROMPT_CHARS) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::PROMPT_CHARS);
        $end = max((int) mb_strrpos($cut, "\n"), (int) mb_strrpos($cut, '. '));
        if ($end > self::PROMPT_CHARS * 0.6) {
            $cut = mb_substr($cut, 0, $end + 1);
        }

        return rtrim($cut).' […]';
    }

    private function score(WritingSample $sample, string $programme, ?string $level, string $country): int
    {
        $score = 0;

        $field = mb_strtolower(trim((string) $sample->field_of_study));
        if ($field !== '' && $programme !== '') {
            if (self::containsPhrase($programme, $field) || self::containsPhrase($field, $programme)) {
                $score += 4;
            } elseif (array_intersect($this->fieldWords($field), $this->fieldWords($programme)) !== []) {
                $score += 2;
            }
        }

        if ($level !== null && RequirementResolver::degreeLevel($sample->degree_level) === $level) {
            $score += 2;
        }

        if ($country !== '' && strtoupper((string) $sample->country_code) === $country) {
            $score += 1;
        }

        return $score;
    }

    /** Whole-word match: "IT" must not match "digital", nor "Law" match "Lawrence". */
    private static function containsPhrase(string $haystack, string $phrase): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u', $haystack) === 1;
    }

    /** @return list<string> */
    private function fieldWords(string $text): array
    {
        preg_match_all('/\p{L}{'.self::MIN_FIELD_WORD.',}/u', $text, $matches);

        return array_values(array_diff($matches[0], ['with', 'studies', 'science', 'sciences', 'master', 'masters', 'bachelor', 'degree', 'programme', 'program']));
    }

    private function enabled(AiJob $job): bool
    {
        return (bool) $job->config('writing_samples.enabled', true);
    }

    private function limit(AiJob $job): int
    {
        return max(0, min(self::MAX_SAMPLES, (int) $job->config('writing_samples.max_samples', 2)));
    }
}
