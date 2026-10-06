<?php

namespace App\Domain\Ai\Pipeline;

use App\Domain\Ai\Stages;
use App\Enums\PipelineStage;
use Illuminate\Contracts\Container\Container;

/** Maps each pipeline stage to its handler (resolved from the container). */
class StageRegistry
{
    private const HANDLERS = [
        'ingestion' => Stages\IngestionStage::class,
        'analysis' => Stages\AnalysisStage::class,
        'research' => Stages\ResearchStage::class,
        'verification' => Stages\VerificationStage::class,
        'requirements' => Stages\RequirementsStage::class,
        'strategy' => Stages\StrategyStage::class,
        'writing' => Stages\WritingStage::class,
        'editorial' => Stages\EditorialStage::class,
        'fact_check' => Stages\FactCheckStage::class,
        'quality_review' => Stages\QualityReviewStage::class,
        'limits' => Stages\LimitsStage::class,
        'formatting' => Stages\FormattingStage::class,
        'rendering' => Stages\RenderingStage::class,
        'file_qa' => Stages\FileQaStage::class,
        'delivery' => Stages\DeliveryStage::class,
    ];

    public function __construct(private readonly Container $app) {}

    public function for(PipelineStage $stage): Stages\Stage
    {
        return $this->app->make(self::HANDLERS[$stage->value]);
    }
}
