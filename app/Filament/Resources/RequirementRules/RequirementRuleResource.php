<?php

namespace App\Filament\Resources\RequirementRules;

use App\Filament\Resources\RequirementRules\Pages\CreateRequirementRule;
use App\Filament\Resources\RequirementRules\Pages\EditRequirementRule;
use App\Filament\Resources\RequirementRules\Pages\ListRequirementRules;
use App\Filament\Resources\RequirementRules\Schemas\RequirementRuleForm;
use App\Filament\Resources\RequirementRules\Tables\RequirementRulesTable;
use App\Models\RequirementRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Country, platform, institution, programme and scholarship requirements, each with its source. */
class RequirementRuleResource extends Resource
{
    protected static ?string $model = RequirementRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Requirement rules';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RequirementRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RequirementRulesTable::configure($table);
    }

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'institution_name', 'programme_name'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRequirementRules::route('/'),
            'create' => CreateRequirementRule::route('/create'),
            'edit' => EditRequirementRule::route('/{record}/edit'),
        ];
    }
}
