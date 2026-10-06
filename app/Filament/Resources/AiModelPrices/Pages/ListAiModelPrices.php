<?php

namespace App\Filament\Resources\AiModelPrices\Pages;

use App\Filament\Resources\AiModelPrices\AiModelPriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiModelPrices extends ListRecords
{
    protected static string $resource = AiModelPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
