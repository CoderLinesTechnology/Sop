<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Concerns\ReadsMetricsRange;

/** Where the orders created in the period are now. */
class OrdersByStatusChart extends ChartWidgetBase
{
    use ReadsMetricsRange;

    protected static ?int $sort = 4;

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return AdminContext::can(Permission::DashboardView);
    }

    public function getHeading(): string
    {
        return 'Orders by status';
    }

    public function getDescription(): string
    {
        return 'Orders created in the last '.$this->rangeLabel().' (unpaid drafts excluded), by current status.';
    }

    protected function getData(): array
    {
        $counts = $this->metrics()->ordersByStatus();

        if ($counts === []) {
            return [];
        }

        $statuses = array_map(fn (string $value) => OrderStatus::tryFrom($value), array_keys($counts));

        return [
            'datasets' => [[
                'label' => 'Orders',
                'data' => array_values($counts),
                'backgroundColor' => array_map(fn (?OrderStatus $status) => self::COLORS[$status?->getColor() ?? 'gray'] ?? self::COLORS['gray'], $statuses),
                'borderRadius' => 3,
                'barThickness' => 14,
            ]],
            'labels' => array_map(fn (?OrderStatus $status, string $value) => $status?->getLabel() ?? $value, $statuses, array_keys($counts)),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
                'y' => ['grid' => ['display' => false]],
            ],
        ];
    }
}
