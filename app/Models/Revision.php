<?php

namespace App\Models;

use App\Enums\RevisionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/** A revision request linked to its original order (never a separate order). */
#[Fillable(['uuid', 'order_id', 'number', 'request_text', 'status', 'mode', 'fee_amount', 'currency', 'requested_at', 'started_at', 'completed_at', 'rejected_reason', 'admin_note'])]
class Revision extends Model
{
    protected function casts(): array
    {
        return [
            'status' => RevisionStatus::class,
            'fee_amount' => 'integer',
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Revision $revision) => $revision->uuid ??= (string) Str::uuid());
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function aiJob(): HasOne
    {
        return $this->hasOne(AiJob::class)->latestOfMany();
    }

    public function documentVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->latestOfMany();
    }
}
