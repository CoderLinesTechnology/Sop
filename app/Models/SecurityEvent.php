<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Suspicious activity (bad webhook signatures, enumeration, coupon brute force, ...). */
#[Table(timestamps: false)]
#[Fillable(['type', 'severity', 'ip_address', 'email', 'order_id', 'path', 'details', 'created_at'])]
class SecurityEvent extends Model
{
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
