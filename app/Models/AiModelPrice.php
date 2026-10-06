<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/** Per-model price table used to estimate the cost of every AI call (USD). */
#[Unguarded]
class AiModelPrice extends Model
{
    protected function casts(): array
    {
        return [
            'input_per_million' => 'decimal:4',
            'cached_input_per_million' => 'decimal:4',
            'output_per_million' => 'decimal:4',
            'web_search_per_call' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }
}
