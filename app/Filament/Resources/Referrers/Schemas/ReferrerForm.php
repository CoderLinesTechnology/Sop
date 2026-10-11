<?php

namespace App\Filament\Resources\Referrers\Schemas;

use App\Domain\Referrals\PartnerDashboard;
use App\Domain\Referrals\PaymentDetails;
use App\Filament\Support\Operations\Format;
use App\Models\Coupon;
use App\Models\Referrer;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\HtmlString;

class ReferrerForm
{
    public static function configure(Schema $schema): Schema
    {
        $erased = fn (?Referrer $record): bool => (bool) $record?->isErased();

        return $schema
            ->columns(['default' => 1, 'lg' => 2])
            ->components([
                Section::make('Referrer')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->disabled($erased)
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(120),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required(fn (?Referrer $record): bool => ! $record?->isErased())
                            ->maxLength(190)
                            ->helperText('The private dashboard link is emailed here.'),
                        TextInput::make('phone')
                            ->label('Phone')
                            ->tel()
                            ->maxLength(40),
                        TextInput::make('code')
                            ->label('Referral code')
                            ->required()
                            ->maxLength(40)
                            ->regex('/^\s*[A-Za-z0-9_-]+\s*$/')
                            ->validationMessages(['regex' => 'Use only letters, numbers, dashes and underscores.'])
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('code', Referrer::normalizeCode((string) $state)))
                            ->dehydrateStateUsing(fn (?string $state): string => Referrer::normalizeCode((string) $state))
                            ->rules([fn (?Referrer $record): Closure => self::codeIsFree($record)])
                            ->helperText('Saved in upper case. It must differ from every coupon code. Changing it stops links with the old code from working.'),
                        TextInput::make('commission_rate_bp')
                            ->label('Commission rate')
                            ->suffix('%')
                            ->required()
                            ->inputMode('decimal')
                            ->formatStateUsing(fn ($state): ?string => $state === null ? null : rtrim(rtrim(number_format(((int) $state) / 100, 2, '.', ''), '0'), '.'))
                            ->dehydrateStateUsing(fn ($state): int => (int) round(((float) $state) * 100))
                            ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                $value = trim((string) $value);
                                if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value) || (float) $value > 100) {
                                    $fail('Enter a percentage from 0 to 100 with at most two decimals, e.g. 12.5.');
                                }
                            }])
                            ->helperText('Of every payment a referred customer makes (the order and any paid revision). The rate at the time of payment applies.'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('When off, the link and code are ignored for new orders and the dashboard link stops working.')
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label('Internal notes')
                            ->helperText('Only administrators see this (agreement, payout details).')
                            ->maxLength(2000)
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Links and results')
                    ->visibleOn(['edit', 'view'])
                    ->schema([
                        TextEntry::make('share_link')
                            ->label('Share link')
                            ->state(fn (Referrer $record): string => $record->shareUrl())
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                        TextEntry::make('dashboard_link_status')
                            ->label('Private dashboard link')
                            ->state(fn (Referrer $record): string => match (true) {
                                $record->isErased() => 'Erased',
                                ! $record->hasDashboardLink() => 'Disabled',
                                ! $record->is_active => 'Not working while the referrer is inactive',
                                default => 'Active',
                            })
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'gray'),
                        TextEntry::make('payment_details_status')
                            ->label('Payment details')
                            ->state(fn (Referrer $record): string => PaymentDetails::mask($record->payment_details) ?? 'No payment details yet')
                            ->helperText(fn (?Referrer $record): ?string => $record === null ? null : (implode(' · ', array_filter([
                                $record->payment_details_changed_at ? 'Confirmed by the partner on '.Format::dateTime($record->payment_details_changed_at) : null,
                                $record->pending_details_expires_at?->isFuture() ? 'A change is waiting for the partner’s email confirmation' : null,
                            ])) ?: null)),
                        TextEntry::make('results')
                            ->label('Results')
                            ->state(fn (Referrer $record): HtmlString => self::results($record)),
                    ]),
            ]);
    }

    /** Unique among referrers (archived included) and never equal to a coupon code, in either direction. */
    private static function codeIsFree(?Referrer $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            $code = Referrer::normalizeCode((string) $value);
            if ($code === '') {
                return;
            }

            $takenByReferrer = Referrer::withTrashed()
                ->where('code', $code)
                ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                ->exists();

            if ($takenByReferrer) {
                $fail('Another referrer already uses this code.');
            } elseif (Coupon::withTrashed()->where('code', $code)->exists()) {
                $fail('A coupon (possibly archived) already uses this code. Referral codes must differ from coupon codes.');
            }
        };
    }

    private static function results(Referrer $record): HtmlString
    {
        $summary = app(PartnerDashboard::class)->summary($record);

        $lines = [
            'Visits: '.number_format($summary['visits_30_days']).' in the last 30 days, '.number_format($summary['visits_total']).' in total',
            'Orders started: '.number_format($summary['orders_started']).' · paid: '.number_format($summary['paid_orders']),
        ];
        foreach ($summary['money'] as $money) {
            $lines[] = $money['currency'].': revenue '.$money['revenue'].' · commission '.$money['earned']
                .' · pending '.$money['pending'].' · available '.$money['available'].' · requested '.$money['requested'].' · paid '.$money['paid'];
        }

        return new HtmlString(implode('<br>', array_map('e', $lines)));
    }
}
