<?php

namespace App\Filament\Resources\PromptVersions\Pages;

use App\Filament\Resources\PromptVersions\PromptVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromptVersions extends ListRecords
{
    protected static string $resource = PromptVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
