<?php

namespace App\Filament\Resources\DocumentTemplates\Pages;

use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Support\Catalogue\AuditDiff;
use App\Filament\Support\Catalogue\DocumentTemplateData;
use App\Models\DocumentTemplate;
use App\Support\Audit;
use Filament\Actions\DeleteAction;
use Filament\Actions\ReplicateAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

/**
 * @property DocumentTemplate $record
 */
class EditDocumentTemplate extends EditRecord
{
    protected static string $resource = DocumentTemplateResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** @var array<string, mixed> */
    protected array $auditBefore = [];

    protected function getHeaderActions(): array
    {
        return [
            ReplicateAction::make()
                ->label('Duplicate')
                ->beforeReplicaSaved(function (DocumentTemplate $replica): void {
                    $replica->name = Str::limit($replica->name.' (copy)', 120, '');
                    $replica->slug = $this->uniqueSlug($replica->slug.'-copy');
                    $replica->is_default = false;
                    $replica->is_active = false;
                })
                ->after(fn (DocumentTemplate $replica) => Audit::log('document_template.created', $replica, null, ['name' => $replica->name], ['duplicated_from' => $this->record->id]))
                ->successRedirectUrl(fn (DocumentTemplate $replica): string => DocumentTemplateResource::getUrl('edit', ['record' => $replica])),
            DeleteAction::make()
                ->modalDescription('Orders keep the formatting they were rendered with. Services using this template will choose a template automatically.')
                ->after(fn (DocumentTemplate $record) => Audit::log('document_template.deleted', $record, ['name' => $record->name, 'slug' => $record->slug])),
        ];
    }

    protected function beforeSave(): void
    {
        $this->auditBefore = AuditDiff::snapshot($this->record, DocumentTemplateData::AUDITED);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // The default stays the default (and active) until another template takes over.
        if ($this->record->getOriginal('is_default')) {
            $data['is_default'] = true;
            $data['is_active'] = true;
        }

        return DocumentTemplateData::clean($data);
    }

    protected function afterSave(): void
    {
        $template = $this->record->refresh();

        if ($template->is_default) {
            DocumentTemplateData::makeDefault($template);
        }

        [$before, $after] = AuditDiff::changes($this->auditBefore, AuditDiff::snapshot($template, DocumentTemplateData::AUDITED));
        if ($after !== []) {
            Audit::log('document_template.updated', $template, $before, $after);
        }
    }

    private function uniqueSlug(string $base): string
    {
        $base = Str::limit(Str::slug($base), 110, '');
        $slug = $base;
        $i = 2;
        while (DocumentTemplate::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
