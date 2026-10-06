<?php

namespace App\Filament\Resources\PromptVersions;

use App\Filament\Resources\PromptVersions\Pages\CreatePromptVersion;
use App\Filament\Resources\PromptVersions\Pages\EditPromptVersion;
use App\Filament\Resources\PromptVersions\Pages\ListPromptVersions;
use App\Filament\Resources\PromptVersions\Schemas\PromptVersionForm;
use App\Filament\Resources\PromptVersions\Tables\PromptVersionsTable;
use App\Models\PromptVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PromptVersionResource extends Resource
{
    protected static ?string $model = PromptVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PromptVersionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromptVersionsTable::configure($table);
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
            'index' => ListPromptVersions::route('/'),
            'create' => CreatePromptVersion::route('/create'),
            'edit' => EditPromptVersion::route('/{record}/edit'),
        ];
    }
}
