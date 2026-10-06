<?php

namespace App\Domain\Ai\Pipeline;

use App\Enums\AiJobStatus;
use App\Models\AiJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Executes AI jobs in the request-driven runtime.
 *
 * work() atomically claims a 15-minute lease on a job that is runnable, due
 * (next_run_at <= now) and not leased by anyone else, runs one stage through
 * PipelineRunner::run(), persists the outcome (current_stage, next_run_at)
 * and releases the lease — then repeats while the time budget lasts and the
 * next stage is due immediately. A delayed retry simply stays in
 * next_run_at; when the budget runs out with work remaining, the job is
 * continued in a fresh PHP request via a signed loopback trigger.
 *
 * Every stage fits well inside the lease (PipelineRunner::
 * STAGE_TIME_BUDGET_SECONDS), so a lease that expires means the PHP process
 * died: the next claimer turns the attempt still marked "running" into a
 * failed attempt (retry with backoff, or manual review).
 */
class PipelineWorker
{
    public const LEASE_MINUTES = 15;

    public const DEFAULT_BUDGET_SECONDS = 600;

    /** Hard cap on stages per invocation (defence against loops). */
    private const MAX_STAGES_PER_INVOCATION = 40;

    /** drive() outcomes */
    public const IDLE = 'idle';

    public const BUSY = 'busy';

    public const OUT_OF_BUDGET = 'out_of_budget';

    public function __construct(
        private readonly PipelineRunner $runner,
        private readonly PipelineTrigger $trigger,
    ) {}

    /** Run the job's due stages for up to $budgetSeconds, then hand over to a fresh request if work remains. */
    public function work(int $aiJobId, int $budgetSeconds = self::DEFAULT_BUDGET_SECONDS): void
    {
        if ($this->drive($aiJobId, $budgetSeconds) === self::OUT_OF_BUDGET && ($job = AiJob::query()->find($aiJobId))) {
            $this->trigger->continueElsewhere($job);
        }
    }

    /**
     * Run due stages until the job stops, has to wait (retry delay, customer,
     * pause) or the budget is spent. The first due stage always runs with the
     * full stage budget; later stages only get what is left of $budgetSeconds
     * (and are not started with less than PipelineRunner::MIN_STAGE_SECONDS),
     * so one invocation stays within max($budgetSeconds, the stage budget).
     *
     * @return string self::IDLE | self::BUSY (not claimable) | self::OUT_OF_BUDGET
     */
    public function drive(int $aiJobId, int $budgetSeconds = self::DEFAULT_BUDGET_SECONDS): string
    {
        $started = microtime(true);

        for ($i = 0; $i < self::MAX_STAGES_PER_INVOCATION; $i++) {
            $left = (int) floor($budgetSeconds - (microtime(true) - $started));
            if ($i > 0 && $left < PipelineRunner::MIN_STAGE_SECONDS) {
                return self::OUT_OF_BUDGET;
            }

            $token = $this->claim($aiJobId);
            if ($token === null) {
                return $i === 0 ? self::BUSY : self::IDLE;
            }

            $next = $this->runClaimed($aiJobId, $token, $i === 0 ? null : $left);

            if ($next === null || $next->delaySeconds > 0) {
                return self::IDLE;
            }
        }

        return self::OUT_OF_BUDGET;
    }

    /**
     * Recover a job whose lease expired while an attempt was still running.
     * Does not run the stage itself (heartbeat tasks stay short): the attempt
     * is closed as failed and the stage rescheduled with backoff.
     */
    public function recover(int $aiJobId): bool
    {
        $token = $this->claim($aiJobId, requireDue: false);
        if ($token === null) {
            return false;
        }

        $recovered = false;
        $next = null;

        try {
            [$recovered, $next] = $this->runner->recoverInterrupted($aiJobId);
        } finally {
            $this->release($aiJobId, $token, $recovered ? $this->schedule($aiJobId, $next) : []);
        }

        return $recovered;
    }

    /**
     * Development helper (ai:run-pipeline): drive a job to the end inline,
     * waiting for — or with $skipDelays fast-forwarding — retry delays.
     */
    public function runToCompletion(AiJob $job, bool $skipDelays = true, int $maxSeconds = 3600): AiJob
    {
        $deadline = microtime(true) + $maxSeconds;

        while (microtime(true) < $deadline) {
            $job->refresh();
            if (! $job->status->isRunnable() || $job->current_stage === null) {
                break;
            }

            if ($job->next_run_at !== null && $job->next_run_at->isFuture()) {
                if ($skipDelays) {
                    AiJob::query()->whereKey($job->id)->update(['next_run_at' => now()]);
                } else {
                    Sleep::for(max(1, (int) ceil(now()->diffInSeconds($job->next_run_at, true))))->seconds();
                }
            }

            if ($this->drive($job->id, (int) max(1, $deadline - microtime(true))) === self::BUSY) {
                Sleep::for(2)->seconds(); // another process holds the lease
            }
        }

        return $job->refresh();
    }

    /**
     * Atomic lease claim: a single UPDATE that only succeeds for a runnable,
     * due (unless $requireDue is false), unleased job.
     */
    public function claim(int $aiJobId, bool $requireDue = true): ?string
    {
        $token = Str::random(40);
        $now = now();

        $query = AiJob::query()
            ->whereKey($aiJobId)
            ->whereIn('status', [AiJobStatus::Queued->value, AiJobStatus::Running->value])
            ->where(fn ($q) => $q->whereNull('leased_until')->orWhere('leased_until', '<', $now));

        if ($requireDue) {
            $query->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now));
        }

        $affected = $query->update([
            'leased_until' => $now->copy()->addMinutes(self::LEASE_MINUTES),
            'lease_token' => $token,
        ]);

        return $affected === 1 ? $token : null;
    }

    private function runClaimed(int $aiJobId, string $token, ?int $timeBudgetSeconds): ?NextRun
    {
        $next = null;

        try {
            $stage = AiJob::query()->whereKey($aiJobId)->toBase()->value('current_stage');
            if ($stage === null) {
                Log::error('AI job has no current stage; nothing to run.', ['ai_job_id' => $aiJobId]);
                $this->release($aiJobId, $token, ['next_run_at' => null]);

                return null;
            }

            $this->extendTimeLimit();
            $next = $this->runner->run($aiJobId, (string) $stage, $timeBudgetSeconds);
        } catch (Throwable $e) {
            // Infrastructure failure outside the stage handler: try again shortly.
            $this->release($aiJobId, $token, ['next_run_at' => now()->addMinute()]);
            throw $e;
        }

        $this->release($aiJobId, $token, $this->schedule($aiJobId, $next));

        return $next;
    }

    /** Persist a NextRun: current_stage = its stage, next_run_at = now + delay (null when the job stopped). */
    private function schedule(int $aiJobId, ?NextRun $next): array
    {
        if ($next === null) {
            return ['next_run_at' => null];
        }

        return [
            'current_stage' => $next->stage->value,
            'next_run_at' => now()->addSeconds(max(0, $next->delaySeconds)),
        ];
    }

    /** Release our lease (only if we still hold it) together with the scheduling update. */
    private function release(int $aiJobId, string $token, array $updates): void
    {
        AiJob::query()
            ->whereKey($aiJobId)
            ->where('lease_token', $token)
            ->update($updates + ['leased_until' => null, 'lease_token' => null]);
    }

    /** Give each stage a fresh PHP time limit in web contexts (set_time_limit restarts the counter). */
    private function extendTimeLimit(): void
    {
        if (! app()->runningInConsole() && function_exists('set_time_limit')) {
            @set_time_limit(PipelineRunner::STAGE_TIME_BUDGET_SECONDS + 120);
        }
    }
}
