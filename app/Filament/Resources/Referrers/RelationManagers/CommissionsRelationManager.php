<?php

namespace App\Filament\Resources\Referrers\RelationManagers;

use App\Domain\Referrals\ReferralProgramme;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\ReferralPayouts\ReferralPayoutResource;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Format;
use App\Models\ReferralCommission;
use App\Models\Referrer;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The referrer's commission ledger, read-only. Entries are written by
 * App\Domain\Referrals\ReferralCommissions, linked to a payout request when
 * the referrer asks to be paid and stamped paid when the request is paid
 * (Pricing → Payout requests), so the ledger and the requests always agree.
 */
class CommissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'commissions';

    protected static ?string $title = 'Commission ledger';

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
        $holdDays = ReferralProgramme::holdDays();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order:id,public_id,reference', 'paidBy:id,name', 'payout:id,public_id,status']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime(Format::DATETIME)
                    ->sortable(),
                TextColumn::make('kind')
                    ->label('Entry')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ReferralCommission::kindLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => $state === ReferralCommission::KIND_REFUND_REVERSAL ? 'danger' : 'success'),
                TextColumn::make('order.reference')
                    ->label('Order')
                    ->fontFamily(FontFamily::Mono)
                    ->url(fn (ReferralCommission $record): ?string => $record->order && OrderResource::canView($record->order)
                        ? OrderResource::getUrl('view', ['record' => $record->order])
                        : null),
                TextColumn::make('base_amount')
                    ->label('Paid by customer')
                    ->state(fn (ReferralCommission $record): string => Format::money($record->base_amount, $record->currency)),
                TextColumn::make('rate_bp')
                    ->label('Rate')
                    ->formatStateUsing(fn ($state): string => Referrer::formatRate((int) $state)),
                TextColumn::make('amount')
                    ->label('Commission')
                    ->state(fn (ReferralCommission $record): string => Format::money($record->amount, $record->currency))
                    ->color(fn (ReferralCommission $record): ?string => $record->amount < 0 ? 'danger' : null)
                    ->weight(FontWeight::SemiBold),
                TextColumn::make('balance_state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (ReferralCommission $record): string => match (true) {
                        $record->paid_at !== null => 'Paid',
                        $record->payout_id !== null => 'Requested',
                        $record->kind !== ReferralCommission::KIND_REFUND_REVERSAL && $record->created_at->copy()->addDays($holdDays)->isFuture() => 'Pending until '.$record->created_at->copy()->addDays($holdDays)->format(Format::DATE),
                        default => 'Available',
                    })
                    ->color(fn (string $state): string => match (true) {
                        $state === 'Paid' => 'success',
                        $state === 'Requested' => 'warning',
                        str_starts_with($state, 'Pending') => 'gray',
                        default => 'info',
                    })
                    ->description(fn (ReferralCommission $record): ?string => $record->payout_reference
                        ? 'Ref. '.$record->payout_reference.($record->paidBy ? ' · '.$record->paidBy->name : '')
                        : null)
                    ->url(fn (ReferralCommission $record): ?string => $record->payout && ReferralPayoutResource::canView($record->payout)
                        ? ReferralPayoutResource::getUrl('view', ['record' => $record->payout])
                        : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('paid')
                    ->label('Payout')
                    ->trueLabel('Paid out')
                    ->falseLabel('Unpaid')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('paid_at'),
                        false: fn (Builder $query) => $query->whereNull('paid_at'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No commission yet')
            ->emptyStateDescription('Entries appear when a referred customer’s payment is confirmed, and reversals when a refund is processed.');
    }
}
