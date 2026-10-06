<?php

namespace App\Filament\Resources\NewsletterSubscribers\Pages;

use App\Filament\Resources\NewsletterSubscribers\NewsletterSubscriberResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListNewsletterSubscribers extends ListRecords
{
    protected static string $resource = NewsletterSubscriberResource::class;

    public function getSubheading(): ?string
    {
        return 'Only confirmed subscribers (double opt-in) should receive marketing email.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => NewsletterSubscriberResource::can('export'))
                ->url(fn (): string => route('admin.support.exports.newsletter', array_filter([
                    'status' => $this->tableFilters['status']['value'] ?? null,
                ])))
                ->tooltip('Exports the subscribers matching the status filter. The export is audited.'),
        ];
    }
}
