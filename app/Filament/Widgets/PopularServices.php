<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Concerns\ReadsMetricsRange;
use App\Filament\Support\Operations\Format;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Services ranked by paid orders in the period. */
class PopularServices extends TableWidget
{
    use ReadsMetricsRange;

    protected static ?int $sort = 6;

    public static function canView(): bool
    {
        return AdminContext::can(Permission::DashboardView);
    }

    public function table(Table $table): Table
    {
        $metrics = $this->metrics();

        return $table
            ->heading('Popular services')
            ->description('Paid orders in the last '.$this->rangeLabel().'.')
            ->records(function () use ($metrics): array {
                $services = $metrics->popularServices(10);
                $total = max(1, array_sum(array_column($services, 'orders')));

                return collect($services)
                    ->filter(fn (array $row) => $row['orders'] > 0)
                    ->mapWithKeys(fn (array $row) => [$row['service_id'] => $row + ['share' => $row['orders'] / $total]])
                    ->all();
            })
            ->columns([
                TextColumn::make('name')->label('Service')->wrap(),
                TextColumn::make('orders')->label('Paid orders')->numeric()->alignEnd(),
                TextColumn::make('revenue')
                    ->label('Revenue')
                    ->formatStateUsing(fn ($state): string => $metrics->money((int) $state))
                    ->alignEnd(),
                TextColumn::make('share')
                    ->label('Share')
                    ->formatStateUsing(fn ($state): string => Format::percent((float) $state, 0))
                    ->alignEnd(),
            ])
            ->paginated(false)
            ->emptyStateHeading('No paid orders in this period');
    }
}
