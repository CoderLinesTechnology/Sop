<?php

namespace App\Filament\Resources\PromptVersions\Pages;

use App\Filament\Resources\PromptVersions\PromptVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromptVersions extends ListRecords
{
    protected static string $resource = PromptVersionResource::class;

    protected ?string $subheading = 'One active version per prompt key. New versions are drafts until someone with activation rights activates them.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New prompt version'),
        ];
    }
}
