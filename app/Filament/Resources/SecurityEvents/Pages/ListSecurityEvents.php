<?php

namespace App\Filament\Resources\SecurityEvents\Pages;

use App\Filament\Resources\SecurityEvents\SecurityEventResource;
use Filament\Resources\Pages\ListRecords;

class ListSecurityEvents extends ListRecords
{
    protected static string $resource = SecurityEventResource::class;

    public function getSubheading(): ?string
    {
        return 'Suspicious activity detected by the application. High-severity events also alert administrators.';
    }
}
