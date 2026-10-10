<?php

namespace App\Console\Commands;

use App\Domain\Ai\Pipeline\PipelineWorker;
use App\Models\AiJob;
use App\Models\Order;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Drives one AI job in this command-line process until it finishes or has to
 * wait. The web runtime starts it detached (statementra.runtime.pipeline_driver
 * = process) on hosts that cut off long web requests, because a single AI
 * stage can take several minutes. A second copy for the same job finds the
 * job leased and exits at once; long waits are left to the heartbeat.
 */
#[Signature('statementra:pipeline-run {job : AI job uuid}')]
#[Description('Run an AI job\'s due stages in this process (started by the web runtime)')]
class PipelineRun extends Command
{
    /** A worker process stops after this long; the heartbeat starts another if work remains. */
    private const MAX_SECONDS = 3000;

    /** Waits longer than this (a retry backoff) are not slept through. */
    private const MAX_WAIT_SECONDS = 180;

    public function handle(PipelineWorker $worker): int
    {
        $uuid = (string) $this->argument('job');
        $job = Str::isUuid($uuid) ? AiJob::query()->where('uuid', $uuid)->first() : null;
        if (! $job) {
            $this->error('No AI job matches that id.');

            return self::FAILURE;
        }

        $deadline = microtime(true) + self::MAX_SECONDS;

        while (microtime(true) < $deadline) {
            $job->refresh();
            if (! $job->status->isRunnable() || $job->current_stage === null
                || Order::query()->whereKey($job->order_id)->value('paused_at') !== null) {
                break;
            }

            if ($job->next_run_at !== null && $job->next_run_at->isFuture()) {
                $wait = (int) ceil(now()->diffInSeconds($job->next_run_at, true));
                if ($wait > self::MAX_WAIT_SECONDS || microtime(true) + $wait > $deadline) {
                    break;
                }
                Sleep::for(max(1, $wait))->seconds();

                continue;
            }

            if ($worker->drive($job->id, (int) max(60, $deadline - microtime(true))) === PipelineWorker::BUSY) {
                break; // another process holds the job's lease
            }

            Sleep::for(1)->seconds();
        }

        return self::SUCCESS;
    }
}
