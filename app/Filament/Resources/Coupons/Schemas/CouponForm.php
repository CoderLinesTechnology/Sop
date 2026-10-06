<?php

namespace App\Filament\Resources\Coupons\Schemas;

use App\Filament\Support\Operations\DiscountFields;
use App\Models\Coupon;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;

class CouponForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 2])
            ->components([
                Section::make('Coupon')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('code')
                            ->label('Code')
                            ->required()
                            ->maxLength(40)
                            ->regex('/^\s*[A-Za-z0-9_-]+\s*$/')
                            ->validationMessages(['regex' => 'Use only letters, numbers, dashes and underscores.'])
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('code', Coupon::normalizeCode((string) $state)))
                            ->dehydrateStateUsing(fn (?string $state): string => Coupon::normalizeCode((string) $state))
                            ->rules([
                                fn (?Coupon $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                    $code = Coupon::normalizeCode((string) $value);
                                    $taken = $code !== '' && Coupon::withTrashed()
                                        ->where('code', $code)
                                        ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                        ->exists();

                                    if ($taken) {
                                        $fail('Another coupon (possibly archived) already uses this code.');
                                    }
                                },
                            ])
                            ->helperText('Letters, numbers, dashes and underscores; saved in upper case.'),
                        TextInput::make('description')
                            ->label('Description')
                            ->helperText('Internal note, e.g. "Instagram campaign, October".')
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),
                Section::make('Discount')
                    ->description('Either a percentage or a fixed amount. Amounts are entered in major units (e.g. 15.50).')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema(DiscountFields::make(
                        capHelp: 'Caps a percentage discount. Leave empty for no cap.',
                        extra: [
                            DiscountFields::moneyInput('min_order_amount', 'Minimum order amount')
                                ->helperText('Order subtotal (after any promotion) required to use the coupon.'),
                        ],
                    )),
                Section::make('Who can use it')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        Toggle::make('applies_to_all_services')
                            ->label('All services')
                            ->default(true)
                            ->live()
                            ->columnSpanFull(),
                        Select::make('services')
                            ->label('Services')
                            ->relationship('services', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->required(fn (Get $get): bool => ! $get('applies_to_all_services'))
                            ->visible(fn (Get $get): bool => ! $get('applies_to_all_services'))
                            ->columnSpanFull(),
                        Toggle::make('first_time_customers_only')
                            ->label('First-time customers only')
                            ->helperText('Rejected if the email already has a paid order.'),
                        Toggle::make('stackable_with_promotions')
                            ->label('Combine with promotions')
                            ->helperText('Otherwise the larger of the two discounts applies.'),
                        TextInput::make('customer_email')
                            ->label('Only for this email')
                            ->email()
                            ->maxLength(255)
                            ->helperText('Leave empty to allow any customer.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Limits and schedule')
                    ->description('Times are in '.FilamentTimezone::get().'.')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('max_uses')
                            ->label('Maximum uses')
                            ->integer()
                            ->minValue(1)
                            ->helperText('Across all customers; checkouts in progress count. Empty = unlimited.'),
                        TextInput::make('max_uses_per_email')
                            ->label('Maximum uses per email')
                            ->integer()
                            ->minValue(1)
                            ->helperText('Empty = unlimited.'),
                        DateTimePicker::make('starts_at')
                            ->label('Starts')
                            ->seconds(false),
                        DateTimePicker::make('expires_at')
                            ->label('Expires')
                            ->seconds(false)
                            ->after('starts_at'),
                    ]),
            ]);
    }

    /**
     * Keep exactly one discount kind: the hidden field of the other kind is
     * cleared, and a fixed amount always carries a currency.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeDiscount(array $data): array
    {
        return DiscountFields::normalize($data);
    }
}
