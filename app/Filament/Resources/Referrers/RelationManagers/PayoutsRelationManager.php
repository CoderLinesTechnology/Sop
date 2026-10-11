<?php

namespace App\Filament\Resources\Referrers\RelationManagers;

use App\Filament\Resources\ReferralPayouts\ReferralPayoutResource;
use App\Filament\Resources\ReferralPayouts\Schemas\ReferralPayoutInfolist;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Format;
use App\Models\ReferralPayout;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The referrer's payout requests, read-only; they are decided in Pricing → Payout requests. */
class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $title = 'Payout requests';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return AdminContext::allows('view', $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('requested_at')->label('Requested')->dateTime(Format::DATETIME)->sortable(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->state(fn (ReferralPayout $record): string => Format::money($record->amount, $record->currency)),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ReferralPayout $record): string => $record->statusLabel())
                    ->color(fn (string $state): string => ReferralPayoutInfolist::color($state)),
                TextColumn::make('payout_reference')->label('Reference')->placeholder(Format::PLACEHOLDER),
                TextColumn::make('decided_at')->label('Decided')->dateTime(Format::DATETIME)->placeholder(Format::PLACEHOLDER),
            ])
            ->defaultSort('requested_at', 'desc')
            ->recordUrl(fn (ReferralPayout $record): ?string => ReferralPayoutResource::canView($record) ? ReferralPayoutResource::getUrl('view', ['record' => $record]) : null)
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No payout requests yet');
    }
}
