<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Metrics;
use App\Filament\Widgets\Analytics\AnalyticsOverview;
use App\Filament\Widgets\Analytics\ConversionFunnel;
use App\Filament\Widgets\Analytics\ServicePerformance;
use App\Filament\Widgets\RevenueTrendChart;
use BackedEnum;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Reports → Analytics (analytics.view): the conversion funnel from
 * first-party, cookieless analytics events, revenue and order value, coupon
 * usage, processing time, delivery success, revisions, satisfaction and
 * service popularity for the last 7, 30 or 90 days.
 */
class Analytics extends BaseDashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'analytics';

    protected static ?string $title = 'Analytics';

    protected static ?string $navigationLabel = 'Analytics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return AdminContext::can(Permission::AnalyticsView);
    }

    public function getSubheading(): ?string
    {
        return 'Visitors are counted without cookies using a daily-rotating hash, so a returning visitor counts once per day.';
    }

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
            AnalyticsOverview::class,
            ConversionFunnel::class,
            ServicePerformance::class,
            RevenueTrendChart::class,
        ];
    }

    /** @return int|array<string, ?int> */
    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
