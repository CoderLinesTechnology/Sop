<?php

namespace App\Filament\Resources\AiWorkflows\Pages;

use App\Filament\Resources\AiWorkflows\AiWorkflowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiWorkflows extends ListRecords
{
    protected static string $resource = AiWorkflowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
