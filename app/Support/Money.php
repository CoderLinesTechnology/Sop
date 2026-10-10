<?php

namespace App\Support;

/**
 * Money helpers. Amounts are always integers in the currency's minor unit
 * (cents, kobo, pesewas). Every currency Paystack settles in uses two
 * decimal places.
 */
final class Money
{
    public const CURRENCIES = [
        'USD' => ['symbol' => '$', 'name' => 'US Dollar', 'space' => false],
        'NGN' => ['symbol' => '₦', 'name' => 'Nigerian Naira', 'space' => false],
        'GHS' => ['symbol' => 'GH₵', 'name' => 'Ghanaian Cedi', 'space' => false],
        'ZAR' => ['symbol' => 'R', 'name' => 'South African Rand', 'space' => true],
        'KES' => ['symbol' => 'KSh', 'name' => 'Kenyan Shilling', 'space' => true],
    ];

    public static function format(?int $minor, ?string $currency, bool $alwaysDecimals = false): string
    {
        $minor ??= 0;
        $currency = strtoupper($currency ?: 'GHS');
        $meta = self::CURRENCIES[$currency] ?? ['symbol' => $currency.' ', 'space' => false];

        $negative = $minor < 0;
        $minor = abs($minor);
        $hasCents = $minor % 100 !== 0;
        $number = number_format($minor / 100, ($hasCents || $alwaysDecimals) ? 2 : 0);

        return ($negative ? '-' : '').$meta['symbol'].($meta['space'] ? ' ' : '').$number;
    }

    /** Convert a major-unit amount entered by an admin (e.g. "99.50") to minor units. */
    public static function toMinor(float|int|string|null $major): ?int
    {
        if ($major === null || $major === '') {
            return null;
        }

        return (int) round(((float) $major) * 100);
    }

    public static function toMajor(?int $minor): ?float
    {
        return $minor === null ? null : round($minor / 100, 2);
    }

    /**
     * Percentage of $original that $amount represents as a discount, rounded
     * down so we never overstate a saving.
     */
    public static function percentOff(int $original, int $final): int
    {
        if ($original <= 0 || $final >= $original) {
            return 0;
        }

        return (int) floor((($original - $final) / $original) * 100);
    }

    /** @return array<string, string> */
    public static function currencyOptions(): array
    {
        return array_map(fn ($meta) => $meta['name'], self::CURRENCIES);
    }
}
