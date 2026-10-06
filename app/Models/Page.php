<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * CMS page. The home page stores its editable sections in `sections`; legal
 * and standard pages use the Markdown `body`.
 */
#[Unguarded]
class Page extends Model
{
    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /** A section of the home page by key, merged over defaults. */
    public function section(string $key, array $defaults = []): array
    {
        return array_replace($defaults, array_filter(
            (array) data_get($this->sections, $key, []),
            fn ($value) => $value !== null && $value !== '',
        ));
    }
}
