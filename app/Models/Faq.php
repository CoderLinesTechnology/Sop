<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FAQ entry. Scope decides where it appears: general (FAQ page), home,
 * resources, or service (attached to one service's page).
 */
#[Unguarded]
class Faq extends Model
{
    public const SCOPES = [
        'general' => 'FAQ page',
        'home' => 'Home page',
        'resources' => 'Resources page',
        'service' => 'Service page',
    ];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true)->orderBy('display_order')->orderBy('id');
    }
}
