<?php

namespace App\Filament\Resources\Referrers\Pages;

use App\Filament\Resources\Referrers\ReferrerActions;
use App\Filament\Resources\Referrers\ReferrerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** Read-only page for administrators with referrals.view (relation managers are read-only here). */
class ViewReferrer extends ViewRecord
{
    protected static string $resource = ReferrerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ReferrerActions::shareLink(),
            ReferrerActions::paymentDetails(),
        ];
    }
}
