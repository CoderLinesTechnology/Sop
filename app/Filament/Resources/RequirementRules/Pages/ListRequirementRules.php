<?php

namespace App\Filament\Resources\RequirementRules\Pages;

use App\Filament\Resources\RequirementRules\RequirementRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRequirementRules extends ListRecords
{
    protected static string $resource = RequirementRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
