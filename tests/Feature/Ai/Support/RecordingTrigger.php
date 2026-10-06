<?php

namespace Tests\Feature\Ai\Support;

use App\Domain\Ai\Pipeline\PipelineTrigger;
use App\Models\AiJob;

/**
 * Records kicks and loopback continuations instead of running them, so a
 * test can drive the worker step by step (app(PipelineWorker::class)->drive()).
 */
class RecordingTrigger extends PipelineTrigger
{
    /** @var list<int> */
    public array $kicked = [];

    /** @var list<int> */
    public array $continued = [];

    public bool $loopbackAccepts = true;

    public function kick(AiJob $job): void
    {
        $this->kicked[] = (int) $job->id;
    }

    public function continueElsewhere(AiJob $job): bool
    {
        $this->continued[] = (int) $job->id;

        return $this->loopbackAccepts;
    }
}
