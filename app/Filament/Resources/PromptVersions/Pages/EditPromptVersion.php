<?php

namespace App\Filament\Resources\PromptVersions\Pages;

use App\Filament\Resources\PromptVersions\PromptVersionResource;
use App\Filament\Resources\PromptVersions\Tables\PromptVersionsTable;
use App\Filament\Support\Catalogue\AuditDiff;
use App\Filament\Support\Catalogue\PromptActivation;
use App\Models\PromptVersion;
use App\Support\Audit;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Only drafts reach this page (PromptVersionPolicy::update).
 *
 * @property PromptVersion $record
 */
class EditPromptVersion extends EditRecord
{
    protected static string $resource = PromptVersionResource::class;

    /** @var array<string, mixed> */
    protected array $auditBefore = [];

    private const AUDITED = ['label', 'description', 'system_prompt', 'user_template', 'model', 'reasoning_effort'];

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            PromptActivation::action(),
            PromptVersionsTable::compareAction(),
            DeleteAction::make()
                ->after(fn (PromptVersion $record) => Audit::log('prompt.deleted', $record, ['prompt_key' => $record->prompt_key, 'version' => $record->version])),
        ];
    }

    protected function beforeSave(): void
    {
        $this->auditBefore = AuditDiff::snapshot($this->record, self::AUDITED);
    }

    /** The key, number and status of a version are never edited here. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return array_intersect_key($data, array_flip(self::AUDITED));
    }

    protected function afterSave(): void
    {
        [$before, $after] = AuditDiff::changes($this->auditBefore, AuditDiff::snapshot($this->record, self::AUDITED));
        if ($after !== []) {
            Audit::log('prompt.updated', $this->record, $before, $after, ['prompt_key' => $this->record->prompt_key, 'version' => $this->record->version]);
        }
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
