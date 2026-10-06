<?php

namespace App\Filament\Pages;

use App\Filament\Support\Operations\Metrics;
use App\Filament\Widgets\FulfilmentOverview;
use App\Filament\Widgets\OrdersByStatusChart;
use App\Filament\Widgets\OrdersNeedingAttention;
use App\Filament\Widgets\PopularServices;
use App\Filament\Widgets\RevenueTrendChart;
use App\Filament\Widgets\SalesOverview;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

/**
 * The operations dashboard: sales, fulfilment and quality figures for a
 * selectable period, the revenue trend, orders by status, the orders that
 * need attention and the most popular services. Every widget requires
 * dashboard.view; figures are cached for a minute.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                ToggleButtons::make('range')
                    ->label('Period')
                    ->options(Metrics::RANGES)
                    ->default(30)
                    ->inline(),
            ]);
    }

    public function getWidgets(): array
    {
        return [
            SalesOverview::class,
            FulfilmentOverview::class,
            RevenueTrendChart::class,
            OrdersByStatusChart::class,
            OrdersNeedingAttention::class,
            PopularServices::class,
        ];
    }

    /** @return int|array<string, ?int> */
    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
