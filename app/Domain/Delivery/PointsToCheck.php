<?php

namespace App\Domain\Delivery;

use App\Enums\PipelineStage;
use App\Models\AiJob;
use App\Models\DocumentVersion;

/**
 * What Statementra proposed for the customer rather than took from their
 * material (a research focus, a project it chose, a goal), as listed by the
 * narrative strategy. Shown with the delivered document so the customer can
 * check it before submitting and ask for a revision if it is wrong.
 */
final class PointsToCheck
{
    private const MAX_POINTS = 6;

    /** @return list<string> */
    public static function for(DocumentVersion $version): array
    {
        $job = $version->ai_job_id ? AiJob::query()->find($version->ai_job_id) : null;
        $points = (array) data_get($job?->stageOutput(PipelineStage::Strategy), 'strategy.proposals_to_confirm', []);

        $clean = [];
        foreach ($points as $point) {
            $text = is_string($point) ? trim(preg_replace('/\s+/u', ' ', $point) ?? '') : '';
            if ($text !== '' && ! in_array($text, $clean, true)) {
                $clean[] = mb_substr($text, 0, 300);
            }
        }

        return array_slice($clean, 0, self::MAX_POINTS);
    }
}
