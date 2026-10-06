<?php

namespace App\Filament\Resources\AiWorkflows\Pages;

use App\Filament\Resources\AiWorkflows\AiWorkflowResource;
use App\Filament\Support\Catalogue\WorkflowConfigForm;
use App\Filament\Support\Catalogue\WorkflowDefaults;
use App\Models\AiWorkflow;
use App\Support\Audit;
use Filament\Resources\Pages\CreateRecord;

class CreateAiWorkflow extends CreateRecord
{
    protected static string $resource = AiWorkflowResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** New workflows start from the default configuration. */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');
        $this->form->fill([
            'is_active' => true,
            'is_default' => ! AiWorkflow::query()->where('is_default', true)->exists(),
            'config' => AiWorkflow::defaultConfig(),
        ]);
        $this->callHook('afterFill');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['config'] = WorkflowConfigForm::normalise((array) ($data['config'] ?? []));
        $data['version'] = 1;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var AiWorkflow $workflow */
        $workflow = $this->getRecord();

        if ($workflow->is_default) {
            WorkflowDefaults::makeDefault($workflow);
        }

        Audit::log('ai_workflow.created', $workflow, null, [
            'name' => $workflow->name,
            'is_active' => $workflow->is_active,
            'is_default' => $workflow->is_default,
            'fallback_workflow_id' => $workflow->fallback_workflow_id,
            'version' => $workflow->version,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
