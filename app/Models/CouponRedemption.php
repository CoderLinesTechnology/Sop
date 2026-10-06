<?php

namespace App\Models;

use App\Enums\CouponRedemptionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['coupon_id', 'order_id', 'email', 'discount_amount', 'currency', 'status', 'reserved_until', 'redeemed_at', 'released_at'])]
class CouponRedemption extends Model
{
    protected function casts(): array
    {
        return [
            'status' => CouponRedemptionStatus::class,
            'discount_amount' => 'integer',
            'reserved_until' => 'datetime',
            'redeemed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
