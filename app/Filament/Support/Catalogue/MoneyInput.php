<?php

namespace App\Filament\Support\Catalogue;

use App\Support\Money;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * A money field edited in major units ("89.50") and stored in minor units
 * (8950), the way every amount is stored in Statementra.
 */
final class MoneyInput
{
    public const MAX_MAJOR = 100_000_000;

    public static function make(string $name, string $currencyField = 'currency', ?string $fixedCurrency = null): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->inputMode('decimal')
            ->step(0.01)
            ->minValue(0)
            ->maxValue(self::MAX_MAJOR)
            ->prefix(function (Get $get) use ($currencyField, $fixedCurrency): ?string {
                $currency = strtoupper((string) ($fixedCurrency ?? $get($currencyField) ?? ''));

                return Money::CURRENCIES[$currency]['symbol'] ?? ($currency !== '' ? $currency : null);
            })
            ->formatStateUsing(fn (mixed $state): ?float => is_numeric($state) ? Money::toMajor((int) $state) : null)
            ->dehydrateStateUsing(fn (mixed $state): ?int => Money::toMinor(is_numeric($state) ? $state : null));
    }
}
