<?php

namespace App\Domain\Files\Tasks;

use App\Domain\Files\UploadTextExtraction;
use App\Enums\ExtractionStatus;
use App\Models\UploadedFile;

/** Heartbeat task: retries text extraction for uploads whose extraction did not finish. */
class ExtractPendingUploads
{
    public function __construct(private readonly UploadTextExtraction $extraction) {}

    public function __invoke(): void
    {
        UploadedFile::query()
            ->where('extraction_status', ExtractionStatus::Pending->value)
            ->where('extraction_attempts', '<', UploadTextExtraction::MAX_ATTEMPTS)
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->orderBy('id')
            ->limit(10)
            ->pluck('id')
            ->each(fn (int $id) => $this->extraction->run($id));
    }
}
