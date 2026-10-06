<?php

namespace App\Filament\Support\Operations;

use App\Support\Money;
use App\Support\Settings;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;

/**
 * Discount fields shared by coupons and promotions: a percentage OR a fixed
 * amount (never both), a currency for fixed amounts and an optional cap.
 * Money is entered in major units and stored in minor units (Money::toMinor).
 */
final class DiscountFields
{
    /**
     * @param  list<Field>  $extra
     * @return list<Field>
     */
    public static function make(string $capHelp, array $extra = []): array
    {
        $isAmount = fn (Get $get): bool => $get('discount_type') === 'amount';
        $isPercent = fn (Get $get): bool => $get('discount_type') !== 'amount';

        return [
            ToggleButtons::make('discount_type')
                ->label('Discount type')
                ->options(['percent' => 'Percentage', 'amount' => 'Fixed amount'])
                ->inline()
                ->required()
                ->default('percent')
                ->live()
                ->dehydrated(false)
                ->afterStateHydrated(function (ToggleButtons $component, ?Model $record): void {
                    if ($record?->exists) {
                        $component->state(filled($record->amount_off) && blank($record->percent_off) ? 'amount' : 'percent');
                    }
                })
                ->afterStateUpdated(function (Set $set, ?string $state): void {
                    if ($state === 'amount') {
                        $set('percent_off', null);
                        $set('max_discount_amount', null);
                    } else {
                        $set('amount_off', null);
                    }
                })
                ->columnSpanFull(),
            TextInput::make('percent_off')
                ->label('Percentage off')
                ->numeric()
                ->minValue(0.01)
                ->maxValue(100)
                ->suffix('%')
                ->required($isPercent)
                ->prohibits('amount_off')
                ->visible($isPercent),
            self::moneyInput('amount_off', 'Amount off')
                ->required($isAmount)
                ->prohibits('percent_off')
                ->visible($isAmount),
            Select::make('currency')
                ->label('Currency')
                ->options(Money::currencyOptions())
                ->default(Settings::currency())
                ->required($isAmount)
                ->visible($isAmount)
                ->helperText('A fixed discount applies only to orders in this currency.'),
            self::moneyInput('max_discount_amount', 'Maximum discount')
                ->helperText($capHelp)
                ->visible($isPercent),
            ...$extra,
        ];
    }

    /** A money field edited in major units (e.g. 15.50) and stored in minor units (1550). */
    public static function moneyInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0.01)
            ->step(0.01)
            ->formatStateUsing(fn ($state) => ($state === null || $state === '') ? null : Money::toMajor((int) $state))
            ->dehydrateStateUsing(fn ($state) => Money::toMinor($state));
    }

    /**
     * Enforce exactly one discount kind on save: hidden fields are not
     * submitted, so the other kind is cleared explicitly.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $isAmount = filled($data['amount_off'] ?? null) && blank($data['percent_off'] ?? null);

        if ($isAmount) {
            $data['percent_off'] = null;
            $data['max_discount_amount'] = null;
            $data['currency'] = strtoupper((string) ($data['currency'] ?? Settings::currency()));
        } else {
            $data['amount_off'] = null;
            $data['currency'] = null;
        }

        return $data;
    }

    /** "15% off (max $20)" or "$10 off". */
    public static function describe(Model $record): string
    {
        if (filled($record->amount_off) && blank($record->percent_off)) {
            return Money::format((int) $record->amount_off, $record->currency ?: Settings::currency()).' off';
        }

        $percent = rtrim(rtrim(number_format((float) $record->percent_off, 2), '0'), '.');

        return $percent.'% off'.($record->max_discount_amount
            ? ' (max '.Money::format((int) $record->max_discount_amount, $record->currency ?: Settings::currency()).')'
            : '');
    }
}
