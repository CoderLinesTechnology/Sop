<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'payment_id', 'amount', 'currency', 'reason', 'status', 'requested_by', 'requested_by_admin_id',
    'approved_by_admin_id', 'provider_refund_id', 'provider_status', 'idempotency_key', 'processed_at',
    'failure_reason', 'notes',
])]
class Refund extends Model
{
    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'requested_by_admin_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by_admin_id');
    }
}
