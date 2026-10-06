<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Documents\DocumentQa;
use App\Domain\Documents\DocumentRenderer;

/**
 * Validates the rendered files (document engine). A failure triggers one
 * re-render and a second validation; files that still fail are never
 * delivered — the order goes to manual review.
 */
class FileQaStage implements Stage
{
    public function __construct(
        private readonly DocumentQa $qa,
        private readonly DocumentRenderer $renderer,
    ) {}

    public function run(StageContext $ctx): StageResult
    {
        $version = RenderingStage::version($ctx);
        $result = $this->qa->validate($version);
        $rerendered = false;

        if (! $result->passed) {
            $rerendered = true;
            $version = $this->renderer->render($version->fresh());
            $result = $this->qa->validate($version);
        }

        $output = [
            'document_version_id' => $version->id,
            'passed' => $result->passed,
            'rerendered' => $rerendered,
            'checks' => $result->checks,
        ];

        if (! $result->passed) {
            $failures = array_map(fn ($f) => $f['check'].': '.$f['detail'], $result->failures());

            return StageResult::manualReview('file_qa_failed', 'The rendered files failed validation after a re-render: '.implode('; ', array_slice($failures, 0, 5)), $output);
        }

        return StageResult::completed($output);
    }
}
