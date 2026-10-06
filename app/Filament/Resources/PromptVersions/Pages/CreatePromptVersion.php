<?php

namespace App\Filament\Resources\PromptVersions\Pages;

use App\Filament\Resources\PromptVersions\PromptVersionResource;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Models\PromptVersion;
use App\Support\Audit;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Attributes\Url;

/**
 * New prompt versions are always drafts with the next version number of
 * their key. Opened with ?from={id} (or for a key with an active version)
 * the form starts from that version's text.
 */
class CreatePromptVersion extends CreateRecord
{
    protected static string $resource = PromptVersionResource::class;

    #[Url]
    public ?string $from = null;

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $source = filled($this->from) ? PromptVersion::query()->find($this->from) : null;

        $this->form->fill($source ? [
            'prompt_key' => $source->prompt_key,
            'label' => null,
            'description' => null,
            'system_prompt' => $source->system_prompt,
            'user_template' => $source->user_template,
            'model' => $source->model,
            'reasoning_effort' => $source->reasoning_effort,
        ] : []);

        $this->callHook('afterFill');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = PromptVersion::STATUS_DRAFT;
        $data['version'] = PromptVersion::nextVersionNumber((string) $data['prompt_key']);
        $data['created_by_admin_id'] = AdminAccess::user()?->id;
        unset($data['activated_by_admin_id'], $data['activated_at']);

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
        } catch (UniqueConstraintViolationException) {
            // Someone else saved a version of this key at the same moment.
            $data['version'] = PromptVersion::nextVersionNumber((string) $data['prompt_key']);

            return parent::handleRecordCreation($data);
        }
    }

    protected function afterCreate(): void
    {
        /** @var PromptVersion $version */
        $version = $this->getRecord();

        Audit::log('prompt.created', $version, null, [
            'prompt_key' => $version->prompt_key,
            'version' => $version->version,
            'status' => $version->status,
        ], array_filter(['from_version_id' => $this->from ? (int) $this->from : null]));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
