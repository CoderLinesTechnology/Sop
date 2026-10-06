<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServices extends ListRecords
{
    protected static string $resource = ServiceResource::class;

    protected ?string $subheading = 'Everything customers can order. Drag rows to change the order services appear on the site.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New service'),
        ];
    }
}
