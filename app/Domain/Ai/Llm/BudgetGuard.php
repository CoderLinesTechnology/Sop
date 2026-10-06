<?php

namespace App\Domain\Ai\Llm;

use App\Enums\StepStatus;
use App\Models\AiJob;
use App\Models\AiUsage;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * Enforces cost and activity limits before every model call:
 *  - per job (from the workflow snapshot's `limits`): max_cost_usd,
 *    max_llm_calls, max_search_calls and max_duration_minutes;
 *  - platform-wide: Settings `ai.daily_budget_usd` (sum of today's usage).
 *
 * When a job switches to its fallback workflow (or an admin retries a job
 * that stopped on a budget), the usage so far is recorded in the snapshot as
 * `_budget_offset`, giving the new configuration its own allowance.
 *
 * Duration is processing time (the sum of stage durations), so time spent
 * waiting for the customer or paused by an admin is not counted.
 */
class BudgetGuard
{
    /** @throws BudgetExceeded */
    public function assertCanCall(AiJob $job, bool $usesSearch = false): void
    {
        $limits = (array) $job->config('limits', []);
        $offset = (array) $job->config('_budget_offset', []);

        $maxCost = (float) ($limits['max_cost_usd'] ?? 0);
        $cost = (float) $job->total_cost_usd - (float) ($offset['cost_usd'] ?? 0);
        if ($maxCost > 0 && $cost >= $maxCost) {
            throw new BudgetExceeded(sprintf('Job cost $%.4f reached the limit of $%.2f.', $cost, $maxCost), 'max_cost_usd');
        }

        $maxCalls = (int) ($limits['max_llm_calls'] ?? 0);
        $calls = (int) $job->llm_calls - (int) ($offset['llm_calls'] ?? 0);
        if ($maxCalls > 0 && $calls >= $maxCalls) {
            throw new BudgetExceeded("Job made {$calls} model calls (limit {$maxCalls}).", 'max_llm_calls');
        }

        if ($usesSearch && $this->remainingSearchCalls($job) <= 0) {
            throw new BudgetExceeded('Job used all of its web search calls.', 'max_search_calls');
        }

        $maxMinutes = (float) ($limits['max_duration_minutes'] ?? 0);
        $minutes = $this->processingMinutes($job) - ((int) ($offset['duration_ms'] ?? 0)) / 60000;
        if ($maxMinutes > 0 && $minutes >= $maxMinutes) {
            throw new BudgetExceeded(sprintf('Job processing time %.1f min reached the limit of %d min.', $minutes, $maxMinutes), 'max_duration_minutes');
        }

        $daily = (float) Settings::get('ai.daily_budget_usd', 0);
        if ($daily > 0 && ($spent = $this->spentToday()) >= $daily) {
            throw new BudgetExceeded(sprintf('Platform daily AI budget reached ($%.2f of $%.2f).', $spent, $daily), 'daily_budget');
        }
    }

    public function remainingSearchCalls(AiJob $job): int
    {
        $max = (int) $job->config('limits.max_search_calls', 0);
        if ($max <= 0) {
            return PHP_INT_MAX;
        }

        $used = (int) $job->search_calls - (int) $job->config('_budget_offset.search_calls', 0);

        return max(0, $max - $used);
    }

    /** Processing time in minutes: finished stage durations plus the running stage. */
    public function processingMinutes(AiJob $job): float
    {
        $finished = (int) $job->steps()->reorder()->whereNotNull('duration_ms')->sum('duration_ms');

        $running = 0;
        foreach ($job->steps()->reorder()->where('status', StepStatus::Running->value)->whereNotNull('started_at')->pluck('started_at') as $startedAt) {
            $running += max(0, now()->getTimestampMs() - Carbon::parse($startedAt)->getTimestampMs());
        }

        return ($finished + $running) / 60000;
    }

    /** Snapshot of usage counters, stored as `_budget_offset` to grant a fresh allowance. */
    public function offsetFor(AiJob $job): array
    {
        return [
            'cost_usd' => (float) $job->total_cost_usd,
            'llm_calls' => (int) $job->llm_calls,
            'search_calls' => (int) $job->search_calls,
            'duration_ms' => (int) round($this->processingMinutes($job) * 60000),
        ];
    }

    public function spentToday(): float
    {
        return (float) AiUsage::query()->where('created_at', '>=', now()->startOfDay())->sum('estimated_cost_usd');
    }
}
