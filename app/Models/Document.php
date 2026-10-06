<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** The logical deliverable of an order; its content lives in DocumentVersion rows. */
#[Fillable(['uuid', 'order_id', 'kind', 'title', 'current_version_id', 'status'])]
class Document extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (Document $document) => $document->uuid ??= (string) Str::uuid());
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function nextVersionNumber(): int
    {
        return (int) $this->versions()->max('version_number') + 1;
    }
}
