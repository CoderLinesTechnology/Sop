<?php

namespace App\Filament\Resources\RequirementRules\Pages;

use App\Filament\Resources\RequirementRules\RequirementRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRequirementRules extends ListRecords
{
    protected static string $resource = RequirementRuleResource::class;

    protected ?string $subheading = 'Requirements the AI must follow, beyond what it finds on official sources. Official instructions found during research take precedence.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New rule'),
        ];
    }
}
