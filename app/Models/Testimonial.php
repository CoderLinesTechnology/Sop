<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Unguarded]
class Testimonial extends Model
{
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true)->orderBy('display_order');
    }
}
