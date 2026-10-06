<?php

namespace App\Domain\Pricing;

use App\Models\Order;
use App\Models\Service;
use Carbon\CarbonInterface;

/**
 * The single authority for what a customer pays.
 *
 * Order of operations: the service price, then the best running promotion,
 * then a coupon. A coupon that is not stackable with promotions competes with
 * the promotion and the larger discount wins; the customer is told which one
 * applied. Totals never go below zero.
 */
final class PriceCalculator
{
    public function __construct(
        private readonly PromotionResolver $promotions,
        private readonly CouponValidator $coupons,
    ) {}

    public function quote(
        Service $service,
        ?string $couponCode = null,
        ?string $email = null,
        ?Order $order = null,
        ?CarbonInterface $at = null,
    ): Quote {
        $at ??= now();
        $currency = strtoupper($service->currency);
        $base = (int) $service->price;

        [$promotion, $promotionDiscount] = $this->promotions->best($service, $base, $at);

        $coupon = null;
        $couponDiscount = 0;
        $couponStatus = 'none';
        $couponMessage = '';
        $couponCode = $couponCode !== null ? trim($couponCode) : null;

        if ($couponCode !== null && $couponCode !== '') {
            $check = $this->coupons->check($couponCode, $service, $base - $promotionDiscount, $email, $at, $order);

            if (! $check->ok()) {
                $couponStatus = 'invalid';
                $couponMessage = $check->message;
            } else {
                $coupon = $check->coupon;

                if ($promotion && $promotionDiscount > 0 && ! $coupon->stackable_with_promotions) {
                    $couponOnly = $this->coupons->discount($coupon, $base, $currency);

                    if ($couponOnly > $promotionDiscount) {
                        $promotion = null;
                        $promotionDiscount = 0;
                        $couponDiscount = $couponOnly;
                        $couponStatus = 'applied';
                        $couponMessage = 'Coupon applied. It replaces the current offer because it gives you a better price.';
                    } else {
                        $coupon = null;
                        $couponStatus = 'not_combinable';
                        $couponMessage = "This coupon can't be combined with the current offer, which already gives you a better price.";
                    }
                } else {
                    $couponDiscount = $this->coupons->discount($coupon, $base - $promotionDiscount, $currency);
                    $couponStatus = $couponDiscount > 0 ? 'applied' : 'invalid';
                    $couponMessage = $couponDiscount > 0 ? 'Coupon applied.' : "This coupon doesn't reduce the price of this order.";
                    if ($couponDiscount === 0) {
                        $coupon = null;
                    }
                }
            }
        }

        $total = max(0, $base - $promotionDiscount - $couponDiscount);

        return new Quote(
            currency: $currency,
            baseAmount: $base,
            compareAtAmount: $service->compare_at_price ?: null,
            promotion: $promotionDiscount > 0 ? $promotion : null,
            promotionDiscount: $promotionDiscount,
            coupon: $coupon,
            couponDiscount: $couponDiscount,
            couponCode: $coupon?->code,
            couponStatus: $couponStatus,
            couponMessage: $couponMessage,
            total: $total,
            calculatedAt: $at,
        );
    }
}
