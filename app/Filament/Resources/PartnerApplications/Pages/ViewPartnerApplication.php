<?php

namespace App\Filament\Resources\PartnerApplications\Pages;

use App\Filament\Resources\PartnerApplications\PartnerApplicationActions;
use App\Filament\Resources\PartnerApplications\PartnerApplicationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPartnerApplication extends ViewRecord
{
    protected static string $resource = PartnerApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PartnerApplicationActions::approve(),
            PartnerApplicationActions::reject(),
            PartnerApplicationActions::notes(),
            PartnerApplicationActions::delete(),
        ];
    }
}
