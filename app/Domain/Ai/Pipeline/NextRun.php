<?php

namespace App\Domain\Ai\Pipeline;

use App\Enums\PipelineStage;

/** The follow-up execution a stage run asks for (next stage, or a retry after a delay). */
final class NextRun
{
    public function __construct(
        public readonly PipelineStage $stage,
        public readonly int $delaySeconds = 0,
    ) {}
}
