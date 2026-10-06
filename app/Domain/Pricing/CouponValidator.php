<?php

namespace App\Domain\Pricing;

use App\Enums\CouponRedemptionStatus;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Service;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Validates a coupon code for a service, amount and (optionally) email.
 * Email-dependent rules are enforced whenever an email is known and always
 * at payment time. Usage limits count reserved and redeemed uses; the
 * reservation itself is taken under a row lock by CouponReservations.
 */
final class CouponValidator
{
    public function check(
        string $code,
        Service $service,
        int $amount,
        ?string $email,
        CarbonInterface $at,
        ?Order $excludeOrder = null,
    ): CouponCheck {
        $normalized = Coupon::normalizeCode($code);
        if ($normalized === '') {
            return CouponCheck::fail(CouponCheck::NOT_FOUND, 'Please enter a coupon code.');
        }

        $coupon = Coupon::query()->with('services:id')->where('code', $normalized)->first();
        if (! $coupon) {
            return CouponCheck::fail(CouponCheck::NOT_FOUND, "This coupon code isn't valid.");
        }

        if (! $coupon->is_active) {
            return CouponCheck::fail(CouponCheck::INACTIVE, "This coupon code isn't valid.", $coupon);
        }

        if ($coupon->starts_at && $coupon->starts_at->gt($at)) {
            return CouponCheck::fail(CouponCheck::NOT_STARTED, "This coupon isn't active yet.", $coupon);
        }

        if ($coupon->expires_at && $coupon->expires_at->lte($at)) {
            return CouponCheck::fail(CouponCheck::EXPIRED, 'This coupon has expired.', $coupon);
        }

        if (! $coupon->applies_to_all_services && ! $coupon->services->contains('id', $service->id)) {
            return CouponCheck::fail(CouponCheck::SERVICE_NOT_ELIGIBLE, "This coupon can't be used for {$service->name}.", $coupon);
        }

        if ($coupon->amount_off && $coupon->currency && strtoupper($coupon->currency) !== strtoupper($service->currency)) {
            return CouponCheck::fail(CouponCheck::CURRENCY_MISMATCH, "This coupon can't be used for this order.", $coupon);
        }

        if ($coupon->min_order_amount && $amount < $coupon->min_order_amount) {
            return CouponCheck::fail(
                CouponCheck::MIN_AMOUNT,
                'This coupon requires a minimum order of '.Money::format($coupon->min_order_amount, $service->currency).'.',
                $coupon,
            );
        }

        $usesQuery = $coupon->redemptions()->where('status', '!=', CouponRedemptionStatus::Released->value);
        if ($excludeOrder?->exists) {
            $usesQuery->where('order_id', '!=', $excludeOrder->id);
        }

        if ($coupon->max_uses !== null && (clone $usesQuery)->count() >= $coupon->max_uses) {
            return CouponCheck::fail(CouponCheck::MAX_USES, 'This coupon has reached its usage limit.', $coupon);
        }

        $email = $email ? Str::lower(trim($email)) : null;
        if ($email) {
            if ($coupon->customer_email && ! hash_equals($coupon->customer_email, $email)) {
                return CouponCheck::fail(CouponCheck::CUSTOMER_RESTRICTED, "This coupon isn't valid for this email address.", $coupon);
            }

            if ($coupon->max_uses_per_email !== null
                && (clone $usesQuery)->where('email', $email)->count() >= $coupon->max_uses_per_email) {
                return CouponCheck::fail(CouponCheck::PER_EMAIL_LIMIT, "You've already used this coupon.", $coupon);
            }

            if ($coupon->first_time_customers_only) {
                $hasPaidOrder = Order::query()
                    ->where('email', $email)
                    ->paid()
                    ->when($excludeOrder?->exists, fn ($q) => $q->whereKeyNot($excludeOrder->id))
                    ->exists();
                if ($hasPaidOrder) {
                    return CouponCheck::fail(CouponCheck::FIRST_TIME_ONLY, 'This coupon is only for first-time customers.', $coupon);
                }
            }
        } elseif ($coupon->customer_email) {
            // A customer-specific coupon needs the email before it can be confirmed.
            return CouponCheck::fail(CouponCheck::CUSTOMER_RESTRICTED, 'Enter your email address to use this coupon.', $coupon);
        }

        return new CouponCheck(CouponCheck::OK, $coupon, 'Coupon applied.');
    }

    public function discount(Coupon $coupon, int $amount, string $currency): int
    {
        return Discount::compute($amount, $currency, $coupon->percent_off, $coupon->amount_off, $coupon->currency, $coupon->max_discount_amount);
    }
}
