<?php

namespace App\Filament\Resources\Referrers\Tables;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Referrers\ReferrerActions;
use App\Filament\Support\Operations\Format;
use App\Models\Referrer;
use App\Support\Settings;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Referrers with their results. Every figure is a correlated subquery
 * (withSum / withCount) on indexed columns, so a page of referrers costs one
 * query whatever their volume. Money columns are in the site currency;
 * amounts in other currencies are on each referrer's page.
 */
class ReferrersTable
{
    public static function configure(Table $table): Table
    {
        $currency = Settings::currency();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withSum('visits as visits_total', 'visits')
                ->withCount(['orders as paid_orders_count' => fn (Builder $q) => $q->whereIn('payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value])])
                ->withSum(['commissions as revenue_total' => fn (Builder $q) => $q->where('currency', $currency)], 'base_amount')
                ->withSum(['commissions as due_total' => fn (Builder $q) => $q->where('currency', $currency)->whereNull('paid_at')], 'amount'))
            ->description('Money in '.$currency.'. Revenue counts payments that earn commission, net of refunds.')
            ->columns([
                TextColumn::make('name')
                    ->label('Referrer')
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Referrer $record): ?string => $record->email)
                    ->searchable(['name', 'email', 'code'])
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Code')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->sortable(),
                TextColumn::make('commission_rate_bp')
                    ->label('Rate')
                    ->formatStateUsing(fn ($state): string => Referrer::formatRate((int) $state))
                    ->sortable(),
                TextColumn::make('visits_total')
                    ->label('Visits')
                    ->state(fn (Referrer $record): string => Format::number((int) $record->visits_total))
                    ->sortable(),
                TextColumn::make('paid_orders_count')
                    ->label('Paid orders')
                    ->state(fn (Referrer $record): string => Format::number((int) $record->paid_orders_count))
                    ->sortable(),
                TextColumn::make('revenue_total')
                    ->label('Revenue')
                    ->state(fn (Referrer $record): string => Format::money((int) $record->revenue_total, $currency))
                    ->sortable(),
                TextColumn::make('due_total')
                    ->label('Balance due')
                    ->state(fn (Referrer $record): string => Format::money((int) $record->due_total, $currency))
                    ->weight(FontWeight::SemiBold)
                    ->sortable(),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Referrer $record): string => self::state($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Erased' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime(Format::DATE)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make(ReferrerActions::all())->tooltip('More actions'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedUserGroup)
            ->emptyStateHeading('No referrers yet')
            ->emptyStateDescription('Add a referrer to give them a code, a share link and a private dashboard.')
            ->striped()
            ->defaultPaginationPageOption(25);
    }

    public static function state(Referrer $referrer): string
    {
        return match (true) {
            $referrer->isErased() => 'Erased',
            ! $referrer->is_active => 'Inactive',
            default => 'Active',
        };
    }
}
