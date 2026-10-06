<?php

namespace App\Domain\Ai\Tasks;

use App\Domain\Ai\Pipeline\PipelineTrigger;
use App\Domain\Ai\Pipeline\PipelineWorker;
use App\Enums\AiJobStatus;
use App\Enums\StepStatus;
use App\Models\AiJob;
use Illuminate\Support\Facades\Log;

/**
 * Heartbeat task (every 5 minutes) for jobs that exist but stalled:
 *
 *  1. Expired lease while an attempt is still "running" (or an attempt left
 *     running without any lease): the PHP process died. The attempt counts as
 *     failed and the stage is rescheduled with backoff — or, once
 *     limits.max_stage_attempts is used up, the order goes to
 *     PROCESSING_FAILED → MANUAL_REVIEW and admins are notified.
 *  2. Overdue jobs (next_run_at more than 10 minutes ago, unleased, order not
 *     paused) beyond what AdvanceDuePipelines picked up: continued in a fresh
 *     request through the loopback trigger.
 *
 * Bounded and quick: no stage runs inside this task. (Paid orders whose
 * pipeline never started are handled by Payments' StartPendingFulfilment.)
 */
final class RecoverStalledPipelines
{
    public const INTERVAL_SECONDS = 300;

    /** An attempt "running" this long without a live lease is dead. */
    private const STALE_STEP_MINUTES = 20;

    private const OVERDUE_MINUTES = 10;

    public function __construct(
        private readonly PipelineWorker $worker,
        private readonly PipelineTrigger $trigger,
    ) {}

    public function __invoke(): void
    {
        $now = now();

        $interrupted = AiJob::query()
            ->whereIn('status', [AiJobStatus::Queued->value, AiJobStatus::Running->value])
            ->where(function ($query) use ($now) {
                $query->where(fn ($q) => $q->whereNotNull('leased_until')->where('leased_until', '<', $now))
                    ->orWhere(fn ($q) => $q->whereNull('leased_until')->whereHas('steps', fn ($s) => $s
                        ->where('status', StepStatus::Running->value)
                        ->where('started_at', '<', $now->copy()->subMinutes(self::STALE_STEP_MINUTES))));
            })
            ->orderBy('id')
            ->limit(10)
            ->pluck('id');

        foreach ($interrupted as $id) {
            if ($this->worker->recover((int) $id)) {
                Log::warning('Recovered an interrupted AI stage attempt.', ['ai_job_id' => $id]);
            }
        }

        $overdue = AiJob::query()
            ->due()
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<', $now->copy()->subMinutes(self::OVERDUE_MINUTES))
            ->whereHas('order', fn ($q) => $q->whereNull('paused_at'))
            ->orderBy('next_run_at')
            ->limit(5)
            ->get();

        foreach ($overdue as $job) {
            Log::notice('Continuing an overdue AI job.', ['ai_job_id' => $job->id, 'due_since' => $job->next_run_at?->toIso8601String()]);
            $this->trigger->continueElsewhere($job);
        }
    }
}
