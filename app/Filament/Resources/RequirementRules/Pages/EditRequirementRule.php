<?php

namespace App\Filament\Resources\RequirementRules\Pages;

use App\Filament\Resources\RequirementRules\RequirementRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRequirementRule extends EditRecord
{
    protected static string $resource = RequirementRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
