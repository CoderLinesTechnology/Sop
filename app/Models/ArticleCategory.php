<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class ArticleCategory extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('display_order')->orderBy('name');
    }
}
