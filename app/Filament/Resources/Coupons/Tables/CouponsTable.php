<?php

namespace App\Filament\Resources\Coupons\Tables;

use App\Enums\CouponRedemptionStatus;
use App\Filament\Support\Operations\AuditSnapshot;
use App\Filament\Support\Operations\DiscountFields;
use App\Filament\Support\Operations\Format;
use App\Models\Coupon;
use App\Support\Audit;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CouponsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'services',
                'redemptions as redeemed_count' => fn (Builder $q) => $q->where('status', CouponRedemptionStatus::Redeemed->value),
                'redemptions as reserved_count' => fn (Builder $q) => $q->where('status', CouponRedemptionStatus::Reserved->value),
            ]))
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->fontFamily(FontFamily::Mono)
                    ->weight(FontWeight::SemiBold)
                    ->copyable()
                    ->description(fn (Coupon $record): ?string => $record->description)
                    ->searchable(['code', 'description'])
                    ->sortable(),
                TextColumn::make('discount')
                    ->label('Discount')
                    ->state(fn (Coupon $record): string => DiscountFields::describe($record))
                    ->description(fn (Coupon $record): ?string => $record->min_order_amount
                        ? 'Min. order '.Format::money($record->min_order_amount, $record->currency)
                        : null),
                TextColumn::make('applies')
                    ->label('Services')
                    ->state(fn (Coupon $record): string => $record->applies_to_all_services
                        ? 'All services'
                        : $record->services_count.' '.str('service')->plural((int) $record->services_count)),
                TextColumn::make('usage')
                    ->label('Usage')
                    ->state(fn (Coupon $record): string => (int) $record->redeemed_count.' redeemed'
                        .($record->max_uses ? ' of '.$record->max_uses : ''))
                    ->description(fn (Coupon $record): ?string => (int) $record->reserved_count > 0
                        ? $record->reserved_count.' reserved in checkout'
                        : null),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Coupon $record): string => self::state($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Scheduled' => 'info',
                        'Used up', 'Expired' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime(Format::DATETIME)
                    ->placeholder('Immediately')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime(Format::DATETIME)
                    ->placeholder('Never')
                    ->sortable(),
                IconColumn::make('first_time_customers_only')
                    ->label('First-time only')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('stackable_with_promotions')
                    ->label('Stacks with promotions')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('customer_email')
                    ->label('Customer')
                    ->placeholder('Anyone')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime(Format::DATETIME)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('availability')
                    ->label('Status')
                    ->options([
                        'active' => 'Active now',
                        'scheduled' => 'Scheduled',
                        'expired' => 'Expired',
                        'inactive' => 'Switched off',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'active' => $query->where('is_active', true)
                            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
                        'scheduled' => $query->where('is_active', true)->where('starts_at', '>', now()),
                        'expired' => $query->where('expires_at', '<=', now()),
                        'inactive' => $query->where('is_active', false),
                        default => $query,
                    }),
                TernaryFilter::make('applies_to_all_services')
                    ->label('Services')
                    ->trueLabel('All services')
                    ->falseLabel('Selected services'),
                TrashedFilter::make()->label('Archived'),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
                self::restoreAction(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedTicket)
            ->emptyStateHeading('No coupons yet')
            ->emptyStateDescription('Create a code customers can enter at checkout.')
            ->striped()
            ->defaultPaginationPageOption(25);
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label('Archive')
            ->modalHeading('Archive coupon')
            ->modalDescription('The code stops working immediately. Orders that already used it are not affected, and the coupon can be restored.')
            ->modalSubmitActionLabel('Archive')
            ->successNotificationTitle('Coupon archived')
            ->after(fn (Coupon $record) => Audit::log('coupon.deleted', $record, before: AuditSnapshot::of($record, ['services'])));
    }

    public static function restoreAction(): RestoreAction
    {
        return RestoreAction::make()
            ->successNotificationTitle('Coupon restored')
            ->after(fn (Coupon $record) => Audit::log('coupon.restored', $record, after: AuditSnapshot::of($record, ['services'])));
    }

    public static function state(Coupon $coupon): string
    {
        return match (true) {
            $coupon->trashed() => 'Archived',
            ! $coupon->is_active => 'Off',
            $coupon->expires_at !== null && $coupon->expires_at->isPast() => 'Expired',
            $coupon->starts_at !== null && $coupon->starts_at->isFuture() => 'Scheduled',
            $coupon->max_uses !== null && ((int) $coupon->redeemed_count + (int) $coupon->reserved_count) >= $coupon->max_uses => 'Used up',
            default => 'Active',
        };
    }
}
