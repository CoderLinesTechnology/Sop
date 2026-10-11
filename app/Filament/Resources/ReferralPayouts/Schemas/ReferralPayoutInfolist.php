<?php

namespace App\Filament\Resources\ReferralPayouts\Schemas;

use App\Filament\Resources\ReferralPayouts\ReferralPayoutResource;
use App\Filament\Resources\Referrers\ReferrerResource;
use App\Filament\Support\Operations\Format;
use App\Models\ReferralPayout;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

class ReferralPayoutInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 2])
            ->components([
                Callout::make('Check before paying')
                    ->description(fn (ReferralPayout $record): ?string => ReferralPayoutResource::detailsWarning($record))
                    ->warning()
                    ->visible(fn (ReferralPayout $record): bool => ReferralPayoutResource::detailsWarning($record) !== null)
                    ->columnSpanFull(),
                Section::make('Request')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('amount')
                            ->label('Amount')
                            ->state(fn (ReferralPayout $record): string => Format::money($record->amount, $record->currency))
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (ReferralPayout $record): string => $record->statusLabel())
                            ->color(fn (string $state): string => self::color($state)),
                        TextEntry::make('referrer_label')
                            ->label('Partner')
                            ->state(fn (ReferralPayout $record): string => $record->referrer ? $record->referrer->name.' ('.$record->referrer->code.')' : Format::PLACEHOLDER)
                            ->url(fn (ReferralPayout $record): ?string => $record->referrer && ReferrerResource::canView($record->referrer)
                                ? ReferrerResource::getUrl(ReferrerResource::canEdit($record->referrer) ? 'edit' : 'view', ['record' => $record->referrer])
                                : null),
                        TextEntry::make('requested_at')->label('Requested')->dateTime(Format::DATETIME)->placeholder(Format::PLACEHOLDER),
                        TextEntry::make('masked_details')
                            ->label('Pay to')
                            ->state(fn (ReferralPayout $record): string => ReferralPayoutResource::maskedDetails($record))
                            ->helperText('“Show payment details” reveals the full number (recorded in the audit log).'),
                        TextEntry::make('entries')
                            ->label('Ledger entries covered')
                            ->state(fn (ReferralPayout $record): string => (string) $record->commissions()->count()),
                    ]),
                Section::make('Decision')
                    ->columns(2)
                    ->visible(fn (ReferralPayout $record): bool => ! $record->isOpen())
                    ->schema([
                        TextEntry::make('decided_at')->label('Decided')->dateTime(Format::DATETIME)->placeholder(Format::PLACEHOLDER),
                        TextEntry::make('decidedBy.name')->label('By')->placeholder('The partner'),
                        TextEntry::make('payout_reference')->label('Payout reference')->placeholder(Format::PLACEHOLDER)->copyable(),
                        TextEntry::make('rejection_reason')->label('Reason')->placeholder(Format::PLACEHOLDER),
                    ]),
            ]);
    }

    public static function color(string $status): string
    {
        return match ($status) {
            ReferralPayout::STATUS_REQUESTED => 'warning',
            ReferralPayout::STATUS_PAID => 'success',
            ReferralPayout::STATUS_REJECTED => 'danger',
            default => 'gray',
        };
    }
}
