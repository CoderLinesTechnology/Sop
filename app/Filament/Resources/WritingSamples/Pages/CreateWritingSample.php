<?php

namespace App\Filament\Resources\WritingSamples\Pages;

use App\Filament\Resources\WritingSamples\WritingSampleData;
use App\Filament\Resources\WritingSamples\WritingSampleResource;
use App\Filament\Support\Catalogue\AuditDiff;
use App\Filament\Support\Operations\AdminContext;
use App\Models\WritingSample;
use App\Support\Audit;
use Filament\Resources\Pages\CreateRecord;

class CreateWritingSample extends CreateRecord
{
    protected static string $resource = WritingSampleResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $upload = ($data['source'] ?? null) === WritingSample::SOURCE_PASTED ? null : ($data['file'] ?? null);
        $imported = WritingSampleData::import($upload, $data['pasted_text'] ?? null, 'file', 'pasted_text');

        return WritingSampleData::fields($data) + $imported + [
            'rights_confirmed_at' => now(),
            'created_by_admin_id' => AdminContext::require()->id,
        ];
    }

    protected function afterCreate(): void
    {
        /** @var WritingSample $sample */
        $sample = $this->getRecord();

        Audit::log('writing_sample.created', $sample, null, AuditDiff::snapshot($sample, WritingSampleData::AUDITED), ['redactions' => $sample->redactions]);
    }

    protected function getRedirectUrl(): string
    {
        // Straight to the text, so the administrator can check the import and remove names.
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
