<?php

namespace App\Filament\Resources\ReferralPayouts\Pages;

use App\Filament\Resources\ReferralPayouts\ReferralPayoutActions;
use App\Filament\Resources\ReferralPayouts\ReferralPayoutResource;
use Filament\Resources\Pages\ViewRecord;

class ViewReferralPayout extends ViewRecord
{
    protected static string $resource = ReferralPayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ReferralPayoutActions::revealDetails(),
            ReferralPayoutActions::markPaid(),
            ReferralPayoutActions::reject(),
        ];
    }
}
