<?php

namespace App\Filament\Resources\PaymentEvents\Pages;

use App\Filament\Resources\PaymentEvents\PaymentEventResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentEvents extends ListRecords
{
    protected static string $resource = PaymentEventResource::class;

    public function getSubheading(): ?string
    {
        return 'Every webhook received from the payment provider, including deliveries rejected for an invalid signature.';
    }
}
