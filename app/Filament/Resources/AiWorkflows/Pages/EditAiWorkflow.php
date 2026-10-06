<?php

namespace App\Filament\Resources\AiWorkflows\Pages;

use App\Filament\Resources\AiWorkflows\AiWorkflowResource;
use App\Filament\Resources\AiWorkflows\Tables\AiWorkflowsTable;
use App\Filament\Support\Catalogue\AuditDiff;
use App\Filament\Support\Catalogue\WorkflowConfigForm;
use App\Filament\Support\Catalogue\WorkflowDefaults;
use App\Models\AiWorkflow;
use App\Support\Audit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * @property AiWorkflow $record
 */
class EditAiWorkflow extends EditRecord
{
    protected static string $resource = AiWorkflowResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** @var array<string, mixed> */
    protected array $auditBefore = [];

    protected function getHeaderActions(): array
    {
        return [
            AiWorkflowsTable::duplicateAction(),
            DeleteAction::make()
                ->modalDescription('Only workflows that never produced a document can be deleted. Deactivate it instead to stop using it.')
                ->after(fn (AiWorkflow $record) => Audit::log('ai_workflow.deleted', $record, ['name' => $record->name, 'version' => $record->version])),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['config'] = $this->record->effectiveConfig();

        return $data;
    }

    protected function beforeSave(): void
    {
        $this->auditBefore = AuditDiff::snapshot($this->record, ['name', 'slug', 'description', 'is_active', 'is_default', 'fallback_workflow_id', 'version']);
        $this->auditBefore['config'] = $this->record->effectiveConfig();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $config = WorkflowConfigForm::normalise((array) ($data['config'] ?? []), $this->record->config);

        // Only a real change creates a new version (the model bumps it).
        [, $changed] = WorkflowConfigForm::changes($this->record->effectiveConfig(), $config);
        if ($changed === []) {
            unset($data['config']);
        } else {
            $data['config'] = $config;
        }

        if ($this->record->getOriginal('is_default')) {
            $data['is_default'] = true;
            $data['is_active'] = true;
        }

        if ((int) ($data['fallback_workflow_id'] ?? 0) === $this->record->id) {
            $data['fallback_workflow_id'] = null;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $workflow = $this->record->refresh();

        if ($workflow->is_default) {
            WorkflowDefaults::makeDefault($workflow);
        }

        $after = AuditDiff::snapshot($workflow, ['name', 'slug', 'description', 'is_active', 'is_default', 'fallback_workflow_id', 'version']);
        [$before, $changed] = AuditDiff::changes(array_diff_key($this->auditBefore, ['config' => true]), $after);
        [$configBefore, $configAfter] = WorkflowConfigForm::changes($this->auditBefore['config'] ?? [], $workflow->effectiveConfig());

        if ($changed === [] && $configAfter === []) {
            return;
        }

        Audit::log(
            'ai_workflow.updated',
            $workflow,
            $before + array_combine(array_map(fn ($k) => 'config.'.$k, array_keys($configBefore)), $configBefore),
            $changed + array_combine(array_map(fn ($k) => 'config.'.$k, array_keys($configAfter)), $configAfter),
            ['version' => $workflow->version],
        );
    }
}
