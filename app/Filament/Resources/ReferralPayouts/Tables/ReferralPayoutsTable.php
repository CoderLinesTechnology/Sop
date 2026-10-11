<?php

namespace App\Filament\Resources\ReferralPayouts\Tables;

use App\Filament\Resources\ReferralPayouts\ReferralPayoutActions;
use App\Filament\Resources\ReferralPayouts\ReferralPayoutResource;
use App\Filament\Resources\ReferralPayouts\Schemas\ReferralPayoutInfolist;
use App\Filament\Support\Operations\Format;
use App\Models\ReferralPayout;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Payout requests, open ones first; the partner is eager-loaded with the page (no per-row queries). */
class ReferralPayoutsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('requested_at')
                    ->label('Requested')
                    ->dateTime(Format::DATETIME)
                    ->sortable(),
                TextColumn::make('referrer.name')
                    ->label('Partner')
                    ->description(fn (ReferralPayout $record): ?string => $record->referrer?->code)
                    ->weight(FontWeight::SemiBold),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->state(fn (ReferralPayout $record): string => Format::money($record->amount, $record->currency))
                    ->sortable(),
                TextColumn::make('masked_details')
                    ->label('Pay to')
                    ->state(fn (ReferralPayout $record): string => ReferralPayoutResource::maskedDetails($record))
                    ->icon(fn (ReferralPayout $record) => ReferralPayoutResource::detailsWarning($record) ? Heroicon::OutlinedExclamationTriangle : false)
                    ->iconColor('warning')
                    ->tooltip(fn (ReferralPayout $record): ?string => ReferralPayoutResource::detailsWarning($record)),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ReferralPayout $record): string => $record->statusLabel())
                    ->color(fn (string $state): string => ReferralPayoutInfolist::color($state)),
                TextColumn::make('payout_reference')
                    ->label('Reference')
                    ->placeholder(Format::PLACEHOLDER)
                    ->toggleable(),
                TextColumn::make('decided_at')
                    ->label('Decided')
                    ->dateTime(Format::DATETIME)
                    ->placeholder(Format::PLACEHOLDER)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('requested_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ReferralPayout::statusLabels())
                    ->default(ReferralPayout::STATUS_REQUESTED),
            ])
            ->recordActions([
                ViewAction::make(),
                ReferralPayoutActions::markPaid(),
                ReferralPayoutActions::reject(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading('No payout requests')
            ->emptyStateDescription('Partners request payouts from their dashboard once their available balance reaches the minimum.')
            ->striped()
            ->defaultPaginationPageOption(25);
    }
}
