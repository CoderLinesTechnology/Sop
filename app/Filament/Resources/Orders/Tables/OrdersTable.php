<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Support\Operations\Format;
use App\Filament\Support\Operations\OrderStatusGroups;
use App\Models\Order;
use App\Models\Service;
use Carbon\Carbon;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['service' => fn ($q) => $q->select('id', 'name', 'deleted_at')]))
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->copyable()
                    ->weight(FontWeight::SemiBold)
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->state(fn (Order $record): string => $record->customer_name ?: ($record->applicant_name ?: 'No name given'))
                    ->description(fn (Order $record): string => $record->email)
                    ->searchable(['customer_name', 'applicant_name', 'email']),
                TextColumn::make('service_name')
                    ->label('Service')
                    ->state(fn (Order $record): string => $record->serviceName())
                    ->toggleable(),
                TextColumn::make('programme')
                    ->label('Application')
                    ->state(fn (Order $record): ?string => $record->programme ?: null)
                    ->description(fn (Order $record): ?string => $record->institution)
                    ->placeholder(Format::PLACEHOLDER)
                    ->searchable(['institution', 'programme'])
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->description(fn (Order $record): ?string => $record->isPaused() ? 'Paused' : null)
                    ->sortable(),
                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn (?int $state, Order $record): string => Format::money($state, $record->currency))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime(Format::DATETIME)
                    ->sortable(),
                TextColumn::make('delivered_at')
                    ->label('Delivered')
                    ->dateTime(Format::DATETIME)
                    ->placeholder(Format::PLACEHOLDER)
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('drafts')
                    ->label('Unpaid drafts')
                    ->schema([
                        Toggle::make('include_drafts')
                            ->label('Include unpaid drafts')
                            ->helperText('New and form-submitted orders that never reached payment.'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => ($data['include_drafts'] ?? false)
                        ? $query
                        : $query->submitted())
                    ->indicateUsing(fn (array $data): ?string => ($data['include_drafts'] ?? false) ? 'Including unpaid drafts' : null),
                Filter::make('needs_attention')
                    ->label('Needs attention')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', OrderStatusGroups::values(OrderStatusGroups::ATTENTION))),
                Filter::make('paused')
                    ->label('Paused')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('paused_at')),
                SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options(OrderStatus::class),
                SelectFilter::make('payment_status')
                    ->label('Payment status')
                    ->multiple()
                    ->options(PaymentStatus::class),
                SelectFilter::make('service_id')
                    ->label('Service')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => Service::withTrashed()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('created_at')
                    ->label('Created')
                    ->schema([
                        DatePicker::make('from')->label('Created from'),
                        DatePicker::make('until')->label('Created until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '<=', Carbon::parse($date)->endOfDay())))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make('Created from '.Carbon::parse($data['from'])->format(Format::DATE))->removeField('from');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = Indicator::make('Created until '.Carbon::parse($data['until'])->format(Format::DATE))->removeField('until');
                        }

                        return $indicators;
                    }),
            ])
            ->filtersFormColumns(2)
            ->persistFiltersInSession()
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedShoppingBag)
            ->emptyStateHeading('No orders found')
            ->emptyStateDescription('Paid and pending orders appear here. Turn on "Include unpaid drafts" to see abandoned forms.')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }
}
