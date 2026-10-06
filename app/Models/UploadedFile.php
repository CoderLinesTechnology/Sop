<?php

namespace App\Models;

use App\Enums\ExtractionStatus;
use App\Enums\FileScanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer upload. Stored encrypted in private storage under a random path;
 * addressed publicly only by its random UUID, and only by the browser that
 * uploaded it (draft token) or by staff.
 */
#[Fillable([
    'uuid', 'order_id', 'draft_token_hash', 'service_id', 'field_key', 'purpose', 'original_name', 'extension',
    'mime_type', 'size_bytes', 'sha256', 'disk', 'path', 'is_encrypted', 'encryption_key_id', 'scan_status',
    'scan_engine', 'scan_result', 'scanned_at', 'extraction_status', 'extracted_text', 'extracted_chars',
    'page_count', 'uploaded_ip', 'attached_at', 'expires_at',
])]
#[Hidden(['extracted_text', 'path', 'draft_token_hash'])]
class UploadedFile extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'scan_status' => FileScanStatus::class,
            'extraction_status' => ExtractionStatus::class,
            'extracted_text' => 'encrypted',
            'is_encrypted' => 'boolean',
            'size_bytes' => 'integer',
            'scanned_at' => 'datetime',
            'attached_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function isImage(): bool
    {
        return in_array($this->extension, ['jpg', 'jpeg', 'png'], true);
    }

    public function isUsable(): bool
    {
        return $this->scan_status instanceof FileScanStatus && $this->scan_status->isUsable();
    }

    public function purposeLabel(): string
    {
        return ServiceField::UPLOAD_PURPOSES[$this->purpose] ?? 'Document';
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
