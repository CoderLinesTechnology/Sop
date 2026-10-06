<?php

namespace App\Domain\Ai\Pipeline;

use App\Enums\PipelineStage;
use App\Models\AiJob;

/**
 * Which stages a job runs, in which order, and which are enabled.
 *
 * Order pipelines and regenerations run every stage (optional stages can be
 * disabled per workflow: stages.{stage}.enabled). Revision jobs start from the
 * delivered document: writing (revision mode) → fact_check → quality_review →
 * limits → formatting → rendering → file_qa → delivery.
 */
final class StagePlan
{
    private const REVISION_STAGES = [
        PipelineStage::Writing, PipelineStage::FactCheck, PipelineStage::QualityReview, PipelineStage::Limits,
        PipelineStage::Formatting, PipelineStage::Rendering, PipelineStage::FileQa, PipelineStage::Delivery,
    ];

    /** Stages an administrator may never skip. */
    public const UNSKIPPABLE = [PipelineStage::Rendering, PipelineStage::FileQa, PipelineStage::Delivery];

    /** @return list<PipelineStage> */
    public static function stagesFor(AiJob $job): array
    {
        return $job->kind === AiJob::KIND_REVISION ? self::REVISION_STAGES : PipelineStage::ordered();
    }

    public static function first(AiJob $job): PipelineStage
    {
        return self::stagesFor($job)[0];
    }

    /** The stage after $stage in this job's plan (regardless of enabled state). */
    public static function after(AiJob $job, PipelineStage $stage): ?PipelineStage
    {
        $stages = self::stagesFor($job);
        $index = array_search($stage, $stages, true);

        if ($index === false) {
            // Not part of this plan: continue with the first planned stage after it.
            foreach ($stages as $candidate) {
                if ($candidate->position() > $stage->position()) {
                    return $candidate;
                }
            }

            return null;
        }

        return $stages[$index + 1] ?? null;
    }

    public static function isEnabled(AiJob $job, PipelineStage $stage): bool
    {
        if ($stage->isRequired()) {
            return true;
        }

        return (bool) $job->config("stages.{$stage->value}.enabled", true);
    }
}
