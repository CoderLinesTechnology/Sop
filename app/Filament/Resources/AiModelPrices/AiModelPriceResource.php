<?php

namespace App\Filament\Resources\AiModelPrices;

use App\Filament\Resources\AiModelPrices\Pages\ManageAiModelPrices;
use App\Filament\Resources\AiModelPrices\Schemas\AiModelPriceForm;
use App\Filament\Resources\AiModelPrices\Tables\AiModelPricesTable;
use App\Models\AiModelPrice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Per-model token and web-search prices (USD) used for AI cost estimates. */
class AiModelPriceResource extends Resource
{
    protected static ?string $model = AiModelPrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Model prices';

    protected static ?string $modelLabel = 'model price';

    protected static ?string $recordTitleAttribute = 'model';

    public static function form(Schema $schema): Schema
    {
        return AiModelPriceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiModelPricesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAiModelPrices::route('/'),
        ];
    }
}
