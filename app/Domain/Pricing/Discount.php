<?php

namespace App\Domain\Pricing;

/**
 * Discount arithmetic on integer minor units. Percentages are converted to
 * basis points and rounded half-up; a discount never exceeds the amount it
 * applies to, its cap, or (for fixed amounts) applies across currencies.
 */
final class Discount
{
    public static function compute(
        int $amount,
        string $currency,
        string|float|null $percentOff,
        ?int $amountOff,
        ?string $amountCurrency,
        ?int $maxDiscount,
    ): int {
        if ($amount <= 0) {
            return 0;
        }

        $discount = 0;
        if ($percentOff !== null && (float) $percentOff > 0) {
            $basisPoints = (int) round(min(100, (float) $percentOff) * 100);
            $discount = intdiv($amount * $basisPoints + 5000, 10000);
        } elseif ($amountOff !== null && $amountOff > 0) {
            if ($amountCurrency !== null && strtoupper($amountCurrency) !== strtoupper($currency)) {
                return 0;
            }
            $discount = $amountOff;
        }

        if ($maxDiscount !== null && $maxDiscount > 0) {
            $discount = min($discount, $maxDiscount);
        }

        return max(0, min($discount, $amount));
    }
}
