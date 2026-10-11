<?php

namespace App\Filament\Resources\Referrers\Pages;

use App\Filament\Resources\Referrers\ReferrerAudit;
use App\Filament\Resources\Referrers\ReferrerResource;
use App\Support\Audit;
use Filament\Resources\Pages\CreateRecord;

/** A new referrer gets a private dashboard link at once (Referrer::booted()); the token is never audited. */
class CreateReferrer extends CreateRecord
{
    protected static string $resource = ReferrerResource::class;

    protected function afterCreate(): void
    {
        Audit::log('referrer.created', $this->getRecord(), after: ReferrerAudit::snapshot($this->getRecord()));
    }

    /** Straight to the referrer's page, where the share and dashboard links are. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
