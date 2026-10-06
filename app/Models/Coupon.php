<?php

namespace App\Models;

use App\Enums\CouponRedemptionStatus;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A coupon code. Validation and discount calculation live exclusively in
 * App\Domain\Pricing\CouponValidator; the browser never supplies amounts.
 */
#[Unguarded]
class Coupon extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'percent_off' => 'decimal:2',
            'amount_off' => 'integer',
            'max_discount_amount' => 'integer',
            'min_order_amount' => 'integer',
            'max_uses' => 'integer',
            'max_uses_per_email' => 'integer',
            'applies_to_all_services' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'first_time_customers_only' => 'boolean',
            'stackable_with_promotions' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Coupon $coupon) {
            $coupon->code = self::normalizeCode((string) $coupon->code);
            if ($coupon->customer_email !== null) {
                $coupon->customer_email = Str::lower(trim($coupon->customer_email)) ?: null;
            }
        });
    }

    public static function normalizeCode(string $code): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9_-]/', '', trim($code)) ?? '');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /** Reserved + redeemed uses (released reservations do not count). */
    public function countedUses(): int
    {
        return $this->redemptions()->where('status', '!=', CouponRedemptionStatus::Released->value)->count();
    }
}
