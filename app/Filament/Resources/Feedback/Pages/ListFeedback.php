<?php

namespace App\Filament\Resources\Feedback\Pages;

use App\Filament\Resources\Feedback\FeedbackResource;
use App\Filament\Resources\Feedback\Widgets\FeedbackStats;
use Filament\Actions\Action;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListFeedback extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = FeedbackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => FeedbackResource::can('export'))
                ->url(fn (): string => route('admin.support.exports.feedback', array_filter([
                    'service' => $this->tableFilters['service_id']['value'] ?? null,
                    'rating' => $this->tableFilters['rating']['value'] ?? null,
                ]))),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [FeedbackStats::class];
    }
}
