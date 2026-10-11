<?php

namespace App\Filament\Resources\Referrers\RelationManagers;

use App\Domain\Referrals\ReferralAttribution;
use App\Domain\Referrals\ReferralCommissions;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Format;
use App\Models\Order;
use App\Models\Referrer;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only list of the orders attributed to a referrer, with the commission
 * each one earned (one correlated sum per row). Visible to referrals.view; the
 * order link only to administrators who may open orders.
 */
class ReferredOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Referred orders';

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
        /** @var Referrer $referrer */
        $referrer = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('reference')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select(['orders.id', 'orders.public_id', 'orders.reference', 'orders.email', 'orders.status', 'orders.payment_status', 'orders.currency', 'orders.total_amount', 'orders.service_snapshot', 'orders.referrer_id', 'orders.referral_source', 'orders.referred_at', 'orders.created_at'])
                ->withSum(['referralCommissions as commission_total' => fn (Builder $q) => $q->where('referrer_id', $referrer->getKey())], 'amount'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime(Format::DATETIME)
                    ->sortable(),
                TextColumn::make('reference')
                    ->label('Order')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->url(fn (Order $record): ?string => OrderResource::canView($record) ? OrderResource::getUrl('view', ['record' => $record]) : null),
                TextColumn::make('service')
                    ->label('Service')
                    ->state(fn (Order $record): string => (string) (data_get($record->service_snapshot, 'name') ?: 'Application document')),
                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->state(fn (Order $record): string => Format::money($record->total_amount, $record->currency)),
                TextColumn::make('commission_total')
                    ->label('Commission')
                    ->state(fn (Order $record): string => Format::money((int) $record->commission_total, $record->currency))
                    ->description(fn (Order $record): ?string => ReferralCommissions::isSelfReferral($record->email, $referrer) ? 'Self-referral: no commission' : null),
                TextColumn::make('referral_source')
                    ->label('Via')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        ReferralAttribution::SOURCE_CODE => 'Typed code',
                        ReferralAttribution::SOURCE_LINK => 'Link',
                        default => Format::PLACEHOLDER,
                    })
                    ->description(fn (Order $record): ?string => $record->referred_at ? Format::dateTime($record->referred_at) : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('payment_status')
                    ->label('Payment')
                    ->options(PaymentStatus::class),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No referred orders yet');
    }
}
