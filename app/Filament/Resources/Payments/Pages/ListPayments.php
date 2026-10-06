<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\PaymentEvents\PaymentEventResource;
use App\Filament\Resources\Payments\PaymentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('webhookEvents')
                ->label('Webhook deliveries')
                ->icon(Heroicon::OutlinedBolt)
                ->color('gray')
                ->url(fn (): string => PaymentEventResource::getUrl('index'))
                ->visible(fn (): bool => PaymentEventResource::canViewAny()),
        ];
    }
}
