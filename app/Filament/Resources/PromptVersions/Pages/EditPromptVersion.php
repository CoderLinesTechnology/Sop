<?php

namespace App\Filament\Resources\PromptVersions\Pages;

use App\Filament\Resources\PromptVersions\PromptVersionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPromptVersion extends EditRecord
{
    protected static string $resource = PromptVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
