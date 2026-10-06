<?php

namespace App\Filament\Resources\Feedback\Widgets;

use App\Filament\Resources\Feedback\Pages\ListFeedback;
use App\Filament\Support\Operations\Format;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Rating statistics for the feedback currently shown in the table (respects its filters). */
class FeedbackStats extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected static bool $isLazy = false;

    protected function getTablePage(): string
    {
        return ListFeedback::class;
    }

    protected function getStats(): array
    {
        $row = $this->getPageTableQuery()
            ->reorder()
            ->toBase()
            ->selectRaw('COUNT(*) AS total, AVG(rating) AS average, SUM(rating >= 4) AS positive, SUM(rating <= 2) AS negative')
            ->first();

        $total = (int) ($row->total ?? 0);
        $average = $total > 0 ? (float) $row->average : null;

        return [
            Stat::make('Average rating', $average === null ? Format::PLACEHOLDER : number_format($average, 2).' / 5')
                ->description($total.' '.str('rating')->plural($total))
                ->color($average === null ? 'gray' : ($average >= 4 ? 'success' : ($average >= 3 ? 'warning' : 'danger'))),
            Stat::make('Positive (4–5 stars)', $total > 0 ? Format::percent((int) $row->positive / $total, 0) : Format::PLACEHOLDER)
                ->description(((int) ($row->positive ?? 0)).' ratings')
                ->color('success'),
            Stat::make('Negative (1–2 stars)', $total > 0 ? Format::percent((int) $row->negative / $total, 0) : Format::PLACEHOLDER)
                ->description(((int) ($row->negative ?? 0)).' ratings')
                ->color('danger'),
        ];
    }
}
