<?php

namespace App\Domain\Pricing;

use App\Models\Coupon;
use App\Models\Promotion;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * An authoritative price breakdown computed on the server. The browser only
 * ever displays a Quote; it never sends prices or discounts back.
 */
final class Quote
{
    public function __construct(
        public readonly string $currency,
        public readonly int $baseAmount,
        public readonly ?int $compareAtAmount,
        public readonly ?Promotion $promotion,
        public readonly int $promotionDiscount,
        public readonly ?Coupon $coupon,
        public readonly int $couponDiscount,
        public readonly ?string $couponCode,
        public readonly string $couponStatus,
        public readonly string $couponMessage,
        public readonly int $total,
        public readonly CarbonInterface $calculatedAt,
    ) {}

    /** The crossed-out "original" price to display, if any. */
    public function displayOriginal(): ?int
    {
        $candidates = array_filter([$this->compareAtAmount, $this->baseAmount], fn ($v) => $v !== null && $v > $this->total);

        return $candidates ? max($candidates) : null;
    }

    public function savingsPercent(): int
    {
        $original = $this->displayOriginal();

        return $original ? Money::percentOff($original, $this->total) : 0;
    }

    public function totalDiscount(): int
    {
        return $this->promotionDiscount + $this->couponDiscount;
    }

    public function countdownEndsAt(): ?CarbonInterface
    {
        if ($this->promotion && $this->promotionDiscount > 0 && $this->promotion->show_countdown && $this->promotion->ends_at) {
            return $this->promotion->ends_at;
        }

        return null;
    }

    public function couponApplied(): bool
    {
        return $this->coupon !== null && $this->couponDiscount > 0;
    }

    public function format(?int $amount): ?string
    {
        return $amount === null ? null : Money::format($amount, $this->currency);
    }

    /** Snapshot stored on the order for audit and display. */
    public function toSnapshot(): array
    {
        return [
            'currency' => $this->currency,
            'base_amount' => $this->baseAmount,
            'compare_at_amount' => $this->compareAtAmount,
            'display_original' => $this->displayOriginal(),
            'promotion' => $this->promotion ? [
                'id' => $this->promotion->id,
                'name' => $this->promotion->name,
                'label' => $this->promotion->label,
                'percent_off' => $this->promotion->percent_off,
                'amount_off' => $this->promotion->amount_off,
                'ends_at' => $this->promotion->ends_at?->toIso8601String(),
            ] : null,
            'promotion_discount' => $this->promotionDiscount,
            'coupon' => $this->coupon ? [
                'id' => $this->coupon->id,
                'code' => $this->coupon->code,
                'percent_off' => $this->coupon->percent_off,
                'amount_off' => $this->coupon->amount_off,
            ] : null,
            'coupon_discount' => $this->couponDiscount,
            'coupon_status' => $this->couponStatus,
            'total' => $this->total,
            'calculated_at' => $this->calculatedAt->toIso8601String(),
        ];
    }

    /** Safe, display-only representation for JSON responses. */
    public function toPublicArray(): array
    {
        return [
            'currency' => $this->currency,
            'base' => $this->format($this->baseAmount),
            'original' => $this->format($this->displayOriginal()),
            'promotion_label' => $this->promotionDiscount > 0 ? ($this->promotion?->label ?: $this->promotion?->name) : null,
            'promotion_discount' => $this->promotionDiscount > 0 ? $this->format($this->promotionDiscount) : null,
            'coupon_code' => $this->couponApplied() ? $this->coupon->code : null,
            'coupon_discount' => $this->couponApplied() ? $this->format($this->couponDiscount) : null,
            'coupon_status' => $this->couponStatus,
            'coupon_message' => $this->couponMessage,
            'savings_percent' => $this->savingsPercent(),
            'total' => $this->format($this->total),
            'total_minor' => $this->total,
            'countdown_ends_at' => $this->countdownEndsAt()?->toIso8601String(),
            'server_now' => now()->toIso8601String(),
        ];
    }
}
