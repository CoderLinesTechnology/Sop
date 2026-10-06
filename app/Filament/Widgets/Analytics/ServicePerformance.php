<?php

namespace App\Filament\Widgets\Analytics;

use App\Enums\Permission;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Concerns\ReadsMetricsRange;
use App\Filament\Support\Operations\Format;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Service popularity: views, paid orders, revenue and view-to-order conversion. */
class ServicePerformance extends TableWidget
{
    use ReadsMetricsRange;

    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return AdminContext::can(Permission::AnalyticsView);
    }

    public function table(Table $table): Table
    {
        $metrics = $this->metrics();

        return $table
            ->heading('Service popularity')
            ->description('Last '.$this->rangeLabel().'.')
            ->records(fn (): array => collect($metrics->popularServices(10))->keyBy('service_id')->all())
            ->columns([
                TextColumn::make('name')->label('Service')->wrap(),
                TextColumn::make('views')->label('Visitors')->numeric()->alignEnd(),
                TextColumn::make('orders')->label('Paid orders')->numeric()->alignEnd(),
                TextColumn::make('conversion')
                    ->label('Conversion')
                    ->formatStateUsing(fn ($state): string => Format::percent($state === null ? null : (float) $state))
                    ->placeholder(Format::PLACEHOLDER)
                    ->alignEnd(),
                TextColumn::make('revenue')
                    ->label('Revenue')
                    ->formatStateUsing(fn ($state): string => $metrics->money((int) $state))
                    ->alignEnd(),
            ])
            ->paginated(false)
            ->emptyStateHeading('No service activity in this period');
    }
}
