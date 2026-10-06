<?php

namespace App\Models;

use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['order_id', 'ai_job_id', 'url', 'url_hash', 'domain', 'title', 'source_type', 'is_official', 'authority_rank', 'retrieved_at', 'fetch_status', 'http_status', 'content_hash'])]
class ResearchSource extends Model
{
    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'is_official' => 'boolean',
            'retrieved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(ResearchClaim::class);
    }
}
