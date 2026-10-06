<?php

namespace App\Filament\Resources\AiModelPrices;

use App\Filament\Resources\AiModelPrices\Pages\CreateAiModelPrice;
use App\Filament\Resources\AiModelPrices\Pages\EditAiModelPrice;
use App\Filament\Resources\AiModelPrices\Pages\ListAiModelPrices;
use App\Filament\Resources\AiModelPrices\Schemas\AiModelPriceForm;
use App\Filament\Resources\AiModelPrices\Tables\AiModelPricesTable;
use App\Models\AiModelPrice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AiModelPriceResource extends Resource
{
    protected static ?string $model = AiModelPrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return AiModelPriceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiModelPricesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiModelPrices::route('/'),
            'create' => CreateAiModelPrice::route('/create'),
            'edit' => EditAiModelPrice::route('/{record}/edit'),
        ];
    }
}
