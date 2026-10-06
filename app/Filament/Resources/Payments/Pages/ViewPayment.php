<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Payment '.$this->getRecord()->reference;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openOrder')
                ->label('Open order')
                ->icon(Heroicon::OutlinedShoppingBag)
                ->color('gray')
                ->url(function (): ?string {
                    /** @var Payment $payment */
                    $payment = $this->getRecord();

                    return $payment->order ? OrderResource::getUrl('view', ['record' => $payment->order]) : null;
                })
                ->visible(function (): bool {
                    /** @var Payment $payment */
                    $payment = $this->getRecord();

                    return $payment->order !== null && OrderResource::canView($payment->order);
                }),
        ];
    }
}
