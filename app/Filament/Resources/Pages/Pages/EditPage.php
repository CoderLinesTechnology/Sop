<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use App\Filament\Support\Catalogue\AuditDiff;
use App\Filament\Support\Catalogue\PageSections;
use App\Models\Page;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property Page $record
 */
class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    /** @var array<string, mixed> */
    protected array $auditBefore = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewOnSite')
                ->label('View on site')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn (): string => url($this->record->slug === 'home' ? '/' : '/'.$this->record->slug), shouldOpenInNewTab: true)
                ->visible(fn (): bool => (bool) $this->record->is_published),
            DeleteAction::make()
                ->after(fn (Page $record) => Audit::log('page.deleted', $record, ['slug' => $record->slug, 'title' => $record->title])),
        ];
    }

    protected function beforeSave(): void
    {
        $this->auditBefore = AuditDiff::snapshot($this->record);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $originalSlug = (string) $this->record->getOriginal('slug');

        if (PageSections::isSystemPage($originalSlug)) {
            unset($data['slug'], $data['kind']);
        }

        if ($originalSlug === 'home') {
            $data['is_published'] = true;
        }

        $kind = $data['kind'] ?? $this->record->kind;
        if ($kind === 'home' && $originalSlug !== 'home') {
            $data['kind'] = 'landing';
        }

        // Edit the known keys, keep everything else stored in the sections JSON.
        if (array_key_exists('sections', $data)) {
            $data['sections'] = PageSections::merge($this->record->sections, (array) $data['sections']);
        }

        if (($data['is_published'] ?? false) && blank($data['published_at'] ?? null) && blank($this->record->published_at)) {
            $data['published_at'] = now();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        [$before, $after] = AuditDiff::changes($this->auditBefore, AuditDiff::snapshot($this->record->refresh()));
        if ($after === []) {
            return;
        }

        $tracked = ['title', 'slug', 'kind', 'is_published', 'published_at', 'seo_title'];
        Audit::log(
            'page.updated',
            $this->record,
            array_intersect_key($before, array_flip($tracked)) ?: null,
            array_intersect_key($after, array_flip($tracked)) ?: null,
            ['changed' => array_keys($after)],
        );
    }
}
