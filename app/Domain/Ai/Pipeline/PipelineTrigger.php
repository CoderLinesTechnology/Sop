<?php

namespace App\Domain\Ai\Pipeline;

use App\Models\AiJob;
use App\Support\Runtime\AfterResponse;
use App\Support\Runtime\BackgroundProcess;
use App\Support\Runtime\SelfTrigger;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * How pipeline work gets a PHP process in the request-driven runtime (no
 * queue worker, no cron):
 *
 *  - kick():              run the job's due stages after the current HTTP
 *                         response (immediately in console and tests), once
 *                         the surrounding database transaction has committed;
 *  - continueElsewhere(): fire-and-forget signed loopback request to
 *                         internal.pipeline.continue, so the work continues in
 *                         a fresh PHP request with its own time limit.
 *
 * With statementra.runtime.pipeline_driver = "process" (hosts whose web
 * server stops requests after a couple of minutes, e.g. Hostinger), both
 * start a detached `statementra:pipeline-run` command-line process instead:
 * one AI stage can take several minutes.
 *
 * The job's state (current_stage, next_run_at) is always persisted before a
 * trigger, so a lost trigger is picked up again by the heartbeat or the
 * customer's status-page polling. Bound as a class so tests can substitute it.
 */
class PipelineTrigger
{
    private static int $loopbackDepth = 0;

    /**
     * Within $callback, kick() hands work to a fresh request through the
     * loopback trigger instead of running it in this process (falling back to
     * in-process if the loopback fails). Heartbeat tasks use this to stay short.
     */
    public static function viaLoopback(Closure $callback): mixed
    {
        self::$loopbackDepth++;

        try {
            return $callback();
        } finally {
            self::$loopbackDepth--;
        }
    }

    public function kick(AiJob $job): void
    {
        $id = (int) $job->getKey();
        $remote = self::$loopbackDepth > 0;

        DB::afterCommit(function () use ($id, $job, $remote): void {
            // Falls back to working in this request if no process could be started.
            if ($this->runsInProcesses() && $this->startProcess($job)) {
                return;
            }

            if ($remote && $this->continueElsewhere($job)) {
                return;
            }

            AfterResponse::run('pipeline:'.$id, static fn () => app(PipelineWorker::class)->work($id), PipelineRunner::STAGE_TIME_BUDGET_SECONDS + 300);
        });
    }

    /** @return bool whether the loopback request was accepted */
    public function continueElsewhere(AiJob $job): bool
    {
        if ($this->runsInProcesses()) {
            return $this->startProcess($job);
        }

        try {
            return SelfTrigger::fire('internal.pipeline.continue', ['aiJob' => $job->uuid]);
        } catch (Throwable $e) {
            Log::warning('Could not trigger pipeline continuation; the heartbeat will pick it up.', ['job' => $job->uuid, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Whether pipeline work runs in detached command-line processes. In the
     * console (that worker itself, artisan commands, tests) work always runs inline.
     */
    protected function runsInProcesses(): bool
    {
        return config('statementra.runtime.pipeline_driver') === 'process' && ! app()->runningInConsole();
    }

    /** Start the job's worker process (at most one start per job per minute). */
    private function startProcess(AiJob $job): bool
    {
        // The status page polls every few seconds; an extra worker would find the job leased and exit anyway.
        $throttle = 'pipeline:process:'.$job->getKey();
        if (! Cache::add($throttle, true, now()->addMinute())) {
            return true;
        }

        try {
            $started = app(BackgroundProcess::class)->artisan(['statementra:pipeline-run', (string) $job->uuid]);
        } catch (Throwable $e) {
            // Never let this break the caller (a payment confirmation, a status page): work in-request instead.
            Log::warning('Could not start the pipeline worker process.', ['job' => $job->uuid, 'error' => $e->getMessage()]);
            $started = false;
        }

        if (! $started) {
            Cache::forget($throttle);
        }

        return $started;
    }
}
