<?php

namespace App\Filament\Resources\DocumentTemplates\Pages;

use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocumentTemplates extends ListRecords
{
    protected static string $resource = DocumentTemplateResource::class;

    protected ?string $subheading = 'How delivered PDF and Word documents look. Official requirements (page size, font, margins, spacing) always override the template.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New template'),
        ];
    }
}
