<?php

namespace App\Domain\Ai\Tasks;

use App\Domain\Ai\PipelineDispatcher;

/**
 * Heartbeat task (every minute): continues AI jobs whose next stage is due
 * (new jobs whose kick was lost, retries whose backoff has elapsed, jobs
 * handed over when a worker's time budget ran out). Each job continues in
 * its own fresh request through the signed loopback trigger, so this task
 * returns within seconds.
 */
final class AdvanceDuePipelines
{
    public const INTERVAL_SECONDS = 60;

    public function __construct(private readonly PipelineDispatcher $dispatcher) {}

    public function __invoke(): void
    {
        $this->dispatcher->tickDue(3);
    }
}
