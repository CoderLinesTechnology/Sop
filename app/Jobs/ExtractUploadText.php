<?php

namespace App\Jobs;

use App\Domain\Files\TextExtractor;
use App\Enums\ExtractionStatus;
use App\Models\UploadedFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractUploadText implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $uploadId)
    {
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(TextExtractor $extractor): void
    {
        $file = UploadedFile::query()->find($this->uploadId);

        if (! $file || $file->extraction_status !== ExtractionStatus::Pending || ! $file->isUsable()) {
            return;
        }

        $extractor->extract($file);
    }
}
