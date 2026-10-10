<?php

namespace App\Filament\Resources\WritingSamples\Pages;

use App\Filament\Resources\WritingSamples\WritingSampleData;
use App\Filament\Resources\WritingSamples\WritingSampleResource;
use App\Filament\Support\Catalogue\AuditDiff;
use App\Filament\Support\Operations\AdminContext;
use App\Models\WritingSample;
use App\Support\Audit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

/**
 * @property WritingSample $record
 */
class EditWritingSample extends EditRecord
{
    protected static string $resource = WritingSampleResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** @var array<string, mixed> */
    protected array $auditBefore = [];

    protected string $contentHashBefore = '';

    protected bool $fileReplaced = false;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription('The sample is deleted for good and will not be shown to the AI again. Documents already written are not affected.')
                ->after(fn (WritingSample $record) => Audit::log('writing_sample.deleted', $record, AuditDiff::snapshot($record, WritingSampleData::AUDITED))),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // The text is a hidden attribute, so it is not part of the record's array form.
        $data['content'] = (string) $this->record->content;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        try {
            $imported = WritingSampleData::import($data['replacement_file'] ?? null, $data['content'] ?? '', 'replacement_file', 'content');
        } catch (ValidationException $e) {
            $this->data['replacement_file'] = []; // the refused upload has been deleted
            throw $e;
        }

        if ($imported['source'] === WritingSample::SOURCE_PASTED) {
            // An edit of the existing text: it keeps its origin, and new redactions add to the earlier ones.
            $imported['source'] = $this->record->source;
            $imported['redactions'] = self::addCounts((array) $this->record->redactions, $imported['redactions']);
        } else {
            // A different document: its permission was confirmed again on the form.
            $this->fileReplaced = true;
            $imported['rights_confirmed_at'] = now();
            $imported['rights_confirmed_by_admin_id'] = AdminContext::require()->id;
        }

        return WritingSampleData::fields($data) + $imported;
    }

    protected function beforeSave(): void
    {
        $this->auditBefore = AuditDiff::snapshot($this->record, WritingSampleData::AUDITED);
        $this->contentHashBefore = hash('sha256', (string) $this->record->content);
    }

    protected function afterSave(): void
    {
        $sample = $this->record->refresh();
        $textChanged = hash('sha256', (string) $sample->content) !== $this->contentHashBefore;

        [$before, $after] = AuditDiff::changes($this->auditBefore, AuditDiff::snapshot($sample, WritingSampleData::AUDITED));
        $meta = array_filter(['text_changed' => $textChanged, 'file_replaced' => $this->fileReplaced]);
        if ($after !== [] || $meta !== []) {
            Audit::log('writing_sample.updated', $sample, $before, $after, $meta);
        }

        // Show the stored text (with redactions applied) and clear the replacement upload.
        $this->fillForm();
    }

    /**
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     * @return array<string, int>
     */
    private static function addCounts(array $a, array $b): array
    {
        foreach ($b as $kind => $count) {
            $a[$kind] = (int) ($a[$kind] ?? 0) + $count;
        }

        return $a;
    }
}
