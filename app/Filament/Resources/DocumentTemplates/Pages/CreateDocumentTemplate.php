<?php

namespace App\Filament\Resources\DocumentTemplates\Pages;

use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Support\Catalogue\AuditDiff;
use App\Filament\Support\Catalogue\DocumentTemplateData;
use App\Models\DocumentTemplate;
use App\Support\Audit;
use Filament\Resources\Pages\CreateRecord;

class CreateDocumentTemplate extends CreateRecord
{
    protected static string $resource = DocumentTemplateResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! DocumentTemplate::query()->where('is_default', true)->exists()) {
            $data['is_default'] = true;
        }

        return DocumentTemplateData::clean($data);
    }

    protected function afterCreate(): void
    {
        /** @var DocumentTemplate $template */
        $template = $this->getRecord();

        if ($template->is_default) {
            DocumentTemplateData::makeDefault($template);
        }

        Audit::log('document_template.created', $template, null, AuditDiff::snapshot($template, DocumentTemplateData::AUDITED));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
