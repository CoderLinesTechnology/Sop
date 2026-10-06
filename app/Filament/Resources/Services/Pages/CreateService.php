<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\Catalogue\AuditDiff;
use App\Filament\Support\Catalogue\ServiceAudit;
use App\Models\Service;
use App\Support\Audit;
use Filament\Resources\Pages\CreateRecord;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    /** The service and its order form are saved together or not at all. */
    protected ?bool $hasDatabaseTransactions = true;

    protected function afterCreate(): void
    {
        /** @var Service $service */
        $service = $this->getRecord();

        Audit::log('service.created', $service, null, AuditDiff::snapshot($service, [
            'name', 'slug', 'document_kind', 'price', 'compare_at_price', 'currency', 'is_active', 'ai_workflow_id', 'document_template_id',
        ]), ['form_fields' => $service->fields()->pluck('key')->all()]);

        ServiceAudit::notifyFormWarnings($service);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
