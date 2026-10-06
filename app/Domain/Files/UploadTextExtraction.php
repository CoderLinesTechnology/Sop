<?php

namespace App\Domain\Files;

use App\Enums\ExtractionStatus;
use App\Models\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Extracts text from an upload right after it is received (after the upload
 * response is sent). The heartbeat retries extractions that did not finish,
 * and the AI pipeline's ingestion stage can call it directly as a last resort.
 */
class UploadTextExtraction
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly TextExtractor $extractor) {}

    public function run(int $uploadId): void
    {
        $claimed = UploadedFile::query()->whereKey($uploadId)
            ->where('extraction_status', ExtractionStatus::Pending->value)
            ->where('extraction_attempts', '<', self::MAX_ATTEMPTS)
            ->update(['extraction_attempts' => DB::raw('extraction_attempts + 1'), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }

        $file = UploadedFile::query()->find($uploadId);
        if (! $file || ! $file->isUsable()) {
            return;
        }

        try {
            $this->extractor->extract($file);
        } catch (Throwable $e) {
            report($e);
            if ($file->refresh()->extraction_attempts >= self::MAX_ATTEMPTS) {
                $file->forceFill(['extraction_status' => ExtractionStatus::Failed])->save();
            }
        }
    }
}
