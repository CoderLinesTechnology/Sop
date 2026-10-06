<?php

namespace App\Models;

use App\Enums\PaymentRecordStatus;
use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One payment attempt (one Paystack transaction reference). */
#[Fillable([
    'order_id', 'revision_id', 'purpose', 'provider', 'reference', 'access_code', 'authorization_url', 'amount',
    'currency', 'status', 'provider_transaction_id', 'channel', 'gateway_response', 'customer_email', 'fees',
    'paid_at', 'verified_at', 'verification_data', 'failure_reason', 'mismatch_details', 'ip_address', 'expires_at',
])]
#[Hidden(['access_code', 'verification_data'])]
class Payment extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PaymentRecordStatus::class,
            'amount' => 'integer',
            'fees' => 'integer',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'verification_data' => 'array',
            'mismatch_details' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(Revision::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** Amount still refundable after committed (approved/processing/processed) refunds. */
    public function refundableAmount(): int
    {
        $committed = (int) $this->refunds()
            ->whereIn('status', array_map(fn (RefundStatus $s) => $s->value, array_filter(RefundStatus::cases(), fn ($s) => $s->isCommitted())))
            ->sum('amount');

        return max(0, (int) $this->amount - $committed);
    }
}
