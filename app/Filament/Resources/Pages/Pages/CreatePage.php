<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use App\Filament\Support\Catalogue\PageSections;
use App\Support\Audit;
use Filament\Resources\Pages\CreateRecord;

class CreatePage extends CreateRecord
{
    protected static string $resource = PageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The home kind belongs to the home page only.
        if (($data['kind'] ?? null) === 'home') {
            $data['kind'] = 'landing';
        }

        if (PageSections::usesSections($data['kind'] ?? null)) {
            $data['sections'] = PageSections::merge([], $data['sections'] ?? []);
        }

        if (($data['is_published'] ?? false) && blank($data['published_at'] ?? null)) {
            $data['published_at'] = now();
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        Audit::log('page.created', $this->getRecord(), null, [
            'slug' => $this->getRecord()->slug,
            'kind' => $this->getRecord()->kind,
            'is_published' => (bool) $this->getRecord()->is_published,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
