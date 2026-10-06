<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Operations\OperationsAudit;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        OperationsAudit::viewed('customer.viewed', $this->getRecord());
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->name ?: $this->getRecord()->email;
    }
}
