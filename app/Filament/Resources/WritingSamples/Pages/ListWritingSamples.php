<?php

namespace App\Filament\Resources\WritingSamples\Pages;

use App\Filament\Resources\WritingSamples\WritingSampleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWritingSamples extends ListRecords
{
    protected static string $resource = WritingSampleResource::class;

    protected ?string $subheading = 'Strong SOPs, motivation letters, essays and CVs that the AI studies for structure, tone and specificity when it writes a document of the same type. It never copies their wording or facts.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add sample'),
        ];
    }
}
