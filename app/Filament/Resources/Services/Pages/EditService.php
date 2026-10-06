<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Services\Tables\ServicesTable;
use App\Filament\Support\Catalogue\AuditDiff;
use App\Filament\Support\Catalogue\ServiceAccess;
use App\Filament\Support\Catalogue\ServiceArchiver;
use App\Filament\Support\Catalogue\ServiceAudit;
use App\Models\Service;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * @property Service $record
 */
class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    /** The service and its order form are saved together or not at all. */
    protected ?bool $hasDatabaseTransactions = true;

    /** @var array<string, mixed> */
    protected array $auditBefore = [];

    /** @var array<int, array<string, mixed>> */
    protected array $fieldsBefore = [];

    public function getSubheading(): string|Htmlable|null
    {
        return match (true) {
            $this->record->trashed() => 'This service is archived. Restore it to make changes visible on the site again.',
            ! $this->record->is_active => 'This service is inactive and hidden from customers.',
            default => null,
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewOnSite')
                ->label('View on site')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn (): string => url('/services/'.$this->record->slug), shouldOpenInNewTab: true)
                ->visible(fn (): bool => $this->record->is_active && ! $this->record->trashed()),
            ActionGroup::make([
                ServicesTable::duplicateAction(),
                DeleteAction::make()
                    ->label('Archive')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->modalHeading('Archive this service?')
                    ->modalDescription('The service disappears from the site and can no longer be ordered. Existing orders are not affected, and you can restore it at any time.')
                    ->modalSubmitActionLabel('Archive')
                    ->using(fn (Service $record): bool => ServiceArchiver::archive($record))
                    ->successNotificationTitle('Service archived'),
                RestoreAction::make()
                    ->using(fn (Service $record): bool => ServiceArchiver::restore($record))
                    ->successNotificationTitle('Service restored (inactive — activate it when ready)'),
                ForceDeleteAction::make()
                    ->label('Delete permanently')
                    ->modalDescription('This permanently deletes the service, its order form and FAQs. This cannot be undone.')
                    ->using(fn (Service $record): bool => ServiceArchiver::forceDelete($record)),
            ]),
        ];
    }

    protected function beforeSave(): void
    {
        $this->auditBefore = AuditDiff::snapshot($this->record);
        $this->fieldsBefore = ServiceAudit::fields($this->record);
    }

    /**
     * Defence in depth: fields outside the editor's permissions are disabled
     * in the form; drop them here as well in case the request was tampered with.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! ServiceAccess::canEditPricing()) {
            $data = array_diff_key($data, array_flip(ServiceAccess::PRICING_ATTRIBUTES));
        }

        if (! ServiceAccess::canEditContent()) {
            $data = array_intersect_key($data, array_flip(ServiceAccess::PRICING_ATTRIBUTES));
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $service = $this->record->refresh();

        [$before, $after] = AuditDiff::changes($this->auditBefore, AuditDiff::snapshot($service));
        $formChanges = ServiceAudit::fieldChanges($this->fieldsBefore, ServiceAudit::fields($service));

        if ($before !== [] || $formChanges !== []) {
            Audit::log('service.updated', $service, $before ?: null, $after ?: null, array_filter([
                'price_changed' => array_key_exists('price', $after) ?: null,
                'active_changed' => array_key_exists('is_active', $after) ?: null,
                'form_fields' => $formChanges ?: null,
            ]));
        }

        ServiceAudit::notifyFormWarnings($service);
    }
}
