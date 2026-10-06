<?php

namespace App\Filament\Support\Operations\Concerns;

use App\Filament\Support\Operations\Metrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * For widgets on the dashboard and the Analytics page: the reporting window
 * comes from the page's "range" filter (7, 30 or 90 days; default 30).
 */
trait ReadsMetricsRange
{
    use InteractsWithPageFilters;

    protected function metrics(): Metrics
    {
        return new Metrics(Metrics::range($this->pageFilters['range'] ?? 30));
    }

    protected function rangeLabel(): string
    {
        return Metrics::range($this->pageFilters['range'] ?? 30).' days';
    }
}
