<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

/**
 * Base for the operations charts: not discovered as a widget on its own
 * (it is abstract) and loaded lazily so the dashboard renders quickly.
 */
abstract class ChartWidgetBase extends ChartWidget
{
    /** Chart colours matching the panel palette (Filament color names → hex). */
    public const COLORS = [
        'success' => '#10b981',
        'info' => '#0ea5e9',
        'warning' => '#f59e0b',
        'danger' => '#f43f5e',
        'gray' => '#a8a29e',
        'primary' => '#12403A',
    ];
}
