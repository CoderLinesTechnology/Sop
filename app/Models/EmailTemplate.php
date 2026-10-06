<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/** Admin-editable transactional email (Markdown body with {{variables}}). */
#[Unguarded]
class EmailTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
