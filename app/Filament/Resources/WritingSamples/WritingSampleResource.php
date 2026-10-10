<?php

namespace App\Filament\Resources\WritingSamples;

use App\Filament\Resources\WritingSamples\Pages\CreateWritingSample;
use App\Filament\Resources\WritingSamples\Pages\EditWritingSample;
use App\Filament\Resources\WritingSamples\Pages\ListWritingSamples;
use App\Filament\Resources\WritingSamples\Schemas\WritingSampleForm;
use App\Filament\Resources\WritingSamples\Tables\WritingSamplesTable;
use App\Models\WritingSample;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Example SOPs, letters, essays and CVs that the writing stages study for style and quality. */
class WritingSampleResource extends Resource
{
    protected static ?string $model = WritingSample::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 35;

    protected static ?string $navigationLabel = 'Writing samples';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return WritingSampleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WritingSamplesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWritingSamples::route('/'),
            'create' => CreateWritingSample::route('/create'),
            'edit' => EditWritingSample::route('/{record}/edit'),
        ];
    }
}
