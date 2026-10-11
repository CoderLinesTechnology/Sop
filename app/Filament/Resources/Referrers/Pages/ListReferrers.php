<?php

namespace App\Filament\Resources\Referrers\Pages;

use App\Filament\Resources\Referrers\ReferrerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReferrers extends ListRecords
{
    protected static string $resource = ReferrerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New referrer'),
        ];
    }
}
