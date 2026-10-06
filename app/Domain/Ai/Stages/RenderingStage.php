<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageFailure;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Documents\DocumentRenderer;
use App\Enums\PipelineStage;
use App\Models\DocumentVersion;

/** Renders the PDF and DOCX of the formatted version (document engine). */
class RenderingStage implements Stage
{
    public function __construct(private readonly DocumentRenderer $renderer) {}

    public function run(StageContext $ctx): StageResult
    {
        $version = self::version($ctx);
        $rendered = $this->renderer->render($version);

        return StageResult::completed([
            'document_version_id' => $rendered->id,
            'has_files' => $rendered->hasFiles(),
        ]);
    }

    /** The version created by the formatting stage of this job. */
    public static function version(StageContext $ctx): DocumentVersion
    {
        $id = $ctx->output(PipelineStage::Formatting)['document_version_id'] ?? null;
        $version = $id ? DocumentVersion::query()->find($id) : null;

        return $version ?? throw StageFailure::permanent('missing_version', 'The formatted document version could not be found.');
    }
}
