<?php

namespace App\Filament\Resources\PromptVersions;

use App\Filament\Resources\PromptVersions\Pages\CreatePromptVersion;
use App\Filament\Resources\PromptVersions\Pages\EditPromptVersion;
use App\Filament\Resources\PromptVersions\Pages\ListPromptVersions;
use App\Filament\Resources\PromptVersions\Pages\ViewPromptVersion;
use App\Filament\Resources\PromptVersions\Schemas\PromptVersionForm;
use App\Filament\Resources\PromptVersions\Schemas\PromptVersionInfolist;
use App\Filament\Resources\PromptVersions\Tables\PromptVersionsTable;
use App\Models\PromptVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Versioned prompts. Drafts are edited freely; a draft only reaches
 * production when someone with prompts.activate activates it.
 */
class PromptVersionResource extends Resource
{
    protected static ?string $model = PromptVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Prompt versions';

    protected static ?string $modelLabel = 'prompt version';

    public static function form(Schema $schema): Schema
    {
        return PromptVersionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PromptVersionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromptVersionsTable::configure($table);
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof PromptVersion ? "{$record->prompt_key} v{$record->version}" : 'Prompt version';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromptVersions::route('/'),
            'create' => CreatePromptVersion::route('/create'),
            'view' => ViewPromptVersion::route('/{record}'),
            'edit' => EditPromptVersion::route('/{record}/edit'),
        ];
    }
}
