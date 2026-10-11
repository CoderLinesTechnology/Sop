<?php

namespace App\Filament\Resources\PartnerApplications;

use App\Filament\Resources\PartnerApplications\Pages\ListPartnerApplications;
use App\Filament\Resources\PartnerApplications\Pages\ViewPartnerApplication;
use App\Filament\Resources\PartnerApplications\Schemas\PartnerApplicationInfolist;
use App\Filament\Resources\PartnerApplications\Tables\PartnerApplicationsTable;
use App\Models\PartnerApplication;
use App\Models\Referrer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * "Become a partner" applications (referrals.view to read, referrals.manage
 * to approve, reject, keep notes or delete). Decisions go through
 * App\Domain\Referrals\PartnerApplications. Each row carries two duplicate
 * signals as correlated subqueries on indexed email columns: the address
 * already belongs to a referrer, and the number of other applications from it.
 */
class PartnerApplicationResource extends Resource
{
    protected static ?string $model = PartnerApplication::class;

    protected static ?string $slug = 'partner-applications';

    protected static ?string $modelLabel = 'partner application';

    protected static ?string $pluralModelLabel = 'partner applications';

    protected static ?string $recordTitleAttribute = 'full_name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Pricing';

    protected static ?int $navigationSort = 42;

    public static function infolist(Schema $schema): Schema
    {
        return PartnerApplicationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PartnerApplicationsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->addSelect([
                'is_referrer' => Referrer::withTrashed()->selectRaw('COUNT(*)')->whereColumn('referrers.email', 'partner_applications.email'),
                'other_applications' => PartnerApplication::query()->from('partner_applications as other')->selectRaw('COUNT(*)')
                    ->whereColumn('other.email', 'partner_applications.email')
                    ->whereColumn('other.id', '!=', 'partner_applications.id'),
            ])
            ->with('decidedBy:id,name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerApplications::route('/'),
            'view' => ViewPartnerApplication::route('/{record}'),
        ];
    }

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['full_name', 'email'];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = (int) Cache::remember('admin:referrals:applications-waiting', 60,
            fn () => PartnerApplication::query()->where('status', PartnerApplication::STATUS_SUBMITTED)->count());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Applications waiting for review';
    }

    /** @return list<string> duplicate warnings for an application loaded through getEloquentQuery() */
    public static function duplicateWarnings(PartnerApplication $application): array
    {
        $isReferrer = $application->getAttributes()['is_referrer'] ?? Referrer::withTrashed()->where('email', $application->email)->count();
        $others = $application->getAttributes()['other_applications'] ?? PartnerApplication::query()->where('email', $application->email)->whereKeyNot($application->getKey())->count();

        return array_values(array_filter([
            (int) $isReferrer > 0 ? 'This email already belongs to a referrer.' : null,
            (int) $others > 0 ? 'This email has '.(int) $others.' other '.str('application')->plural((int) $others).'.' : null,
        ]));
    }
}
