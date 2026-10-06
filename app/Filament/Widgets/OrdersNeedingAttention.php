<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Format;
use App\Filament\Support\Operations\OrderStatusGroups;
use App\Models\Order;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** The orders an administrator should look at next. */
class OrdersNeedingAttention extends TableWidget
{
    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return AdminContext::can(Permission::DashboardView) && OrderResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Needs attention')
            ->description('Manual review, failed processing or delivery, and orders waiting for the customer.')
            ->query(fn (): Builder => Order::query()
                ->with(['service' => fn ($q) => $q->select('id', 'name', 'deleted_at')])
                ->whereIn('status', OrderStatusGroups::values(OrderStatusGroups::ATTENTION)))
            ->defaultSort('updated_at')
            ->columns([
                TextColumn::make('reference')->label('Order')->fontFamily(FontFamily::Mono),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('service_name')->label('Service')->state(fn (Order $record): string => $record->serviceName()),
                TextColumn::make('updated_at')
                    ->label('Waiting since')
                    ->since()
                    ->dateTimeTooltip(Format::DATETIME)
                    ->sortable(),
            ])
            ->recordUrl(fn (Order $record): ?string => OrderResource::canView($record) ? OrderResource::getUrl('view', ['record' => $record]) : null)
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading('Nothing needs attention')
            ->emptyStateDescription('All orders are moving on their own.');
    }
}
