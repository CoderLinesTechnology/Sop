<?php

namespace App\Filament\Resources\AiModelPrices\Pages;

use App\Filament\Resources\AiModelPrices\AiModelPriceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAiModelPrice extends EditRecord
{
    protected static string $resource = AiModelPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
