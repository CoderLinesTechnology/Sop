<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Concerns\ReadsMetricsRange;

/** Daily revenue (bars) and paid orders (line). */
class RevenueTrendChart extends ChartWidgetBase
{
    use ReadsMetricsRange;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    public static function canView(): bool
    {
        return AdminContext::can(Permission::DashboardView);
    }

    public function getHeading(): string
    {
        return 'Revenue and paid orders';
    }

    public function getDescription(): string
    {
        return 'Per day, last '.$this->rangeLabel().'. Revenue in '.$this->metrics()->currency.'.';
    }

    protected function getData(): array
    {
        $trend = $this->metrics()->dailyTrend();

        return [
            'datasets' => [
                [
                    'type' => 'bar',
                    'label' => 'Revenue ('.$this->metrics()->currency.')',
                    'data' => $trend['revenue'],
                    'backgroundColor' => 'rgba(18, 64, 58, 0.75)',
                    'borderColor' => '#12403A',
                    'borderRadius' => 3,
                    'yAxisID' => 'y',
                    'order' => 2,
                ],
                [
                    'type' => 'line',
                    'label' => 'Paid orders',
                    'data' => $trend['orders'],
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => '#f59e0b',
                    'tension' => 0.3,
                    'pointRadius' => 2,
                    'yAxisID' => 'y1',
                    'order' => 1,
                ],
            ],
            'labels' => $trend['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
            'scales' => [
                'y' => ['position' => 'left', 'beginAtZero' => true, 'title' => ['display' => true, 'text' => 'Revenue']],
                'y1' => ['position' => 'right', 'beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'Orders']],
            ],
        ];
    }
}
