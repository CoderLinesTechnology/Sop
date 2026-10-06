<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Single-use magic-link token for optional customer accounts (stored hashed). */
#[Table(timestamps: false)]
#[Fillable(['email', 'token_hash', 'expires_at', 'used_at', 'ip_address', 'created_at'])]
class CustomerLoginToken extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
