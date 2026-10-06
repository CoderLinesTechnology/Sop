<?php

namespace App\Filament\Resources\RequirementRules\Pages;

use App\Filament\Resources\RequirementRules\RequirementRuleResource;
use App\Filament\Support\Catalogue\AuditDiff;
use App\Filament\Support\Catalogue\RequirementRuleAudit;
use App\Models\RequirementRule;
use App\Support\Audit;
use Filament\Actions\DeleteAction;
use Filament\Actions\ReplicateAction;
use Filament\Resources\Pages\EditRecord;

/**
 * @property RequirementRule $record
 */
class EditRequirementRule extends EditRecord
{
    protected static string $resource = RequirementRuleResource::class;

    /** @var array<string, mixed> */
    protected array $auditBefore = [];

    protected function getHeaderActions(): array
    {
        return [
            RequirementRuleAudit::verifyAction()
                ->after(fn () => $this->refreshFormData(['last_verified_at', 'verified_by_admin_id'])),
            ReplicateAction::make()
                ->label('Duplicate')
                ->excludeAttributes(['last_verified_at', 'verified_by_admin_id'])
                ->beforeReplicaSaved(fn (RequirementRule $replica) => $replica->fill(['name' => $replica->name.' (copy)', 'is_active' => false]))
                ->after(fn (RequirementRule $replica) => Audit::log('requirement_rule.created', $replica, null, ['name' => $replica->name], ['duplicated_from' => $this->record->id]))
                ->successRedirectUrl(fn (RequirementRule $replica): string => RequirementRuleResource::getUrl('edit', ['record' => $replica])),
            DeleteAction::make()
                ->after(fn (RequirementRule $record) => Audit::log('requirement_rule.deleted', $record, AuditDiff::snapshot($record, ['name', 'scope', 'country_code', 'institution_name', 'programme_name']))),
        ];
    }

    protected function beforeSave(): void
    {
        $this->auditBefore = AuditDiff::snapshot($this->record, RequirementRuleAudit::auditedAttributes());
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return RequirementRuleData::clean($data);
    }

    protected function afterSave(): void
    {
        [$before, $after] = AuditDiff::changes($this->auditBefore, AuditDiff::snapshot($this->record->refresh(), RequirementRuleAudit::auditedAttributes()));

        if ($after !== []) {
            Audit::log('requirement_rule.updated', $this->record, $before, $after);
        }
    }
}
