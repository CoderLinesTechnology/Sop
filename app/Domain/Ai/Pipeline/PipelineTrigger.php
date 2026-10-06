<?php

namespace App\Domain\Ai\Pipeline;

use App\Models\AiJob;
use App\Support\Runtime\AfterResponse;
use App\Support\Runtime\SelfTrigger;
use Closure;
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
            if ($remote && $this->continueElsewhere($job)) {
                return;
            }

            AfterResponse::run('pipeline:'.$id, static fn () => app(PipelineWorker::class)->work($id), PipelineRunner::STAGE_TIME_BUDGET_SECONDS + 300);
        });
    }

    /** @return bool whether the loopback request was accepted */
    public function continueElsewhere(AiJob $job): bool
    {
        try {
            return SelfTrigger::fire('internal.pipeline.continue', ['aiJob' => $job->uuid]);
        } catch (Throwable $e) {
            Log::warning('Could not trigger pipeline continuation; the heartbeat will pick it up.', ['job' => $job->uuid, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
