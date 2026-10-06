<?php

namespace App\Filament\Resources\RequirementRules\Pages;

use App\Filament\Resources\RequirementRules\RequirementRuleResource;
use App\Filament\Support\Catalogue\AuditDiff;
use App\Filament\Support\Catalogue\RequirementRuleAudit;
use App\Support\Audit;
use Filament\Resources\Pages\CreateRecord;

class CreateRequirementRule extends CreateRecord
{
    protected static string $resource = RequirementRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return RequirementRuleData::clean($data);
    }

    protected function afterCreate(): void
    {
        Audit::log('requirement_rule.created', $this->getRecord(), null, array_filter(
            AuditDiff::snapshot($this->getRecord(), RequirementRuleAudit::auditedAttributes()),
            fn ($value) => $value !== null && $value !== [],
        ));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
