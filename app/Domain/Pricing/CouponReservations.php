<?php

namespace App\Domain\Pricing;

use App\Enums\CouponRedemptionStatus;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Coupon usage accounting. A use is reserved under a row lock on the coupon
 * when payment starts, so concurrent checkouts can never exceed max_uses;
 * it is redeemed when payment is verified and released if payment fails or
 * expires.
 */
final class CouponReservations
{
    public function __construct(private readonly CouponValidator $validator) {}

    /**
     * Re-validate and reserve the order's coupon. Must run inside the caller's
     * transaction. Returns false (and leaves no reservation) if the coupon is
     * no longer valid, e.g. its last use was taken a moment ago.
     */
    public function reserve(Order $order, int $discount, int $holdMinutes): bool
    {
        if (! $order->coupon_id || $discount <= 0) {
            $this->release($order);

            return true;
        }

        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Coupon reservations must run inside a database transaction.');
        }

        // Serialise reservations for this coupon.
        $coupon = Coupon::query()->whereKey($order->coupon_id)->lockForUpdate()->first();
        if (! $coupon) {
            return false;
        }

        $check = $this->validator->check(
            $coupon->code,
            $order->service,
            $order->subtotal_amount - $order->promotion_discount,
            $order->email,
            now(),
            $order,
        );

        if (! $check->ok()) {
            return false;
        }

        CouponRedemption::query()->updateOrCreate(
            ['order_id' => $order->id],
            [
                'coupon_id' => $coupon->id,
                'email' => $order->email,
                'discount_amount' => $discount,
                'currency' => $order->currency,
                'status' => CouponRedemptionStatus::Reserved,
                'reserved_until' => now()->addMinutes($holdMinutes),
                'redeemed_at' => null,
                'released_at' => null,
            ],
        );

        return true;
    }

    /** Payment verified: the reservation becomes a redemption (even if it had lapsed). */
    public function redeem(Order $order): void
    {
        CouponRedemption::query()
            ->where('order_id', $order->id)
            ->update([
                'status' => CouponRedemptionStatus::Redeemed->value,
                'redeemed_at' => now(),
                'released_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function release(Order $order): void
    {
        CouponRedemption::query()
            ->where('order_id', $order->id)
            ->where('status', CouponRedemptionStatus::Reserved->value)
            ->update([
                'status' => CouponRedemptionStatus::Released->value,
                'released_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
