<?php

namespace App\Filament\Resources\Referrers;

use App\Filament\Resources\Referrers\Pages\CreateReferrer;
use App\Filament\Resources\Referrers\Pages\EditReferrer;
use App\Filament\Resources\Referrers\Pages\ListReferrers;
use App\Filament\Resources\Referrers\Pages\ViewReferrer;
use App\Filament\Resources\Referrers\RelationManagers\CommissionsRelationManager;
use App\Filament\Resources\Referrers\RelationManagers\PayoutsRelationManager;
use App\Filament\Resources\Referrers\RelationManagers\ReferredOrdersRelationManager;
use App\Filament\Resources\Referrers\Schemas\ReferrerForm;
use App\Filament\Resources\Referrers\Tables\ReferrersTable;
use App\Models\Referrer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Referral partners (referrals.view / referrals.manage): their code and
 * commission rate, the referred orders and the commission ledger with payouts.
 * Attribution and the ledger are written only by App\Domain\Referrals; this
 * resource edits the referrer and records payouts. Every change is audited.
 */
class ReferrerResource extends Resource
{
    protected static ?string $model = Referrer::class;

    protected static ?string $slug = 'referrers';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Pricing';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return ReferrerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReferrersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ReferredOrdersRelationManager::class,
            CommissionsRelationManager::class,
            PayoutsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferrers::route('/'),
            'create' => CreateReferrer::route('/create'),
            'view' => ViewReferrer::route('/{record}'),
            'edit' => EditReferrer::route('/{record}/edit'),
        ];
    }

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code', 'email'];
    }
}
