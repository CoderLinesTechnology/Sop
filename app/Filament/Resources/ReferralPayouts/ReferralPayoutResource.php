<?php

namespace App\Filament\Resources\ReferralPayouts;

use App\Domain\Referrals\PaymentDetails;
use App\Filament\Resources\ReferralPayouts\Pages\ListReferralPayouts;
use App\Filament\Resources\ReferralPayouts\Pages\ViewReferralPayout;
use App\Filament\Resources\ReferralPayouts\Schemas\ReferralPayoutInfolist;
use App\Filament\Resources\ReferralPayouts\Tables\ReferralPayoutsTable;
use App\Filament\Support\Operations\Format;
use App\Models\ReferralPayout;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * Referral payout requests (referrals.view to see, referrals.payouts to see
 * payment details and to mark paid or reject). Requests are made by
 * referrers from their dashboard; deciding them goes through
 * App\Domain\Referrals\ReferralPayouts, which stamps or releases the ledger.
 */
class ReferralPayoutResource extends Resource
{
    /** Payment details changed this recently before (or after) a request are flagged. */
    public const RECENT_CHANGE_DAYS = 14;

    protected static ?string $model = ReferralPayout::class;

    protected static ?string $slug = 'payout-requests';

    protected static ?string $modelLabel = 'payout request';

    protected static ?string $pluralModelLabel = 'payout requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Pricing';

    protected static ?int $navigationSort = 41;

    public static function infolist(Schema $schema): Schema
    {
        return ReferralPayoutInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReferralPayoutsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['referrer:id,public_id,name,code,email,payment_details_changed_at,erased_at,deleted_at', 'decidedBy:id,name']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferralPayouts::route('/'),
            'view' => ViewReferralPayout::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = (int) Cache::remember('admin:referrals:open-payouts', 60,
            fn () => ReferralPayout::query()->where('status', ReferralPayout::STATUS_REQUESTED)->count());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Payout requests waiting to be paid';
    }

    /** A warning when the referrer's payment details changed shortly before, or after, the request. */
    public static function detailsWarning(ReferralPayout $payout): ?string
    {
        $changedAt = $payout->referrer?->payment_details_changed_at;
        $requestedAt = $payout->requested_at ?? $payout->created_at;
        if ($changedAt === null || $requestedAt === null) {
            return null;
        }

        if ($changedAt->greaterThan($requestedAt)) {
            return 'The partner changed their payment details on '.Format::dateTime($changedAt).', after this request. The request keeps the details it was made with; confirm with the partner before paying.';
        }

        if ($changedAt->greaterThanOrEqualTo($requestedAt->copy()->subDays(self::RECENT_CHANGE_DAYS))) {
            return 'Payment details were added or changed on '.Format::dateTime($changedAt).', within '.self::RECENT_CHANGE_DAYS.' days of this request. Confirm with the partner (for example by phone) before paying.';
        }

        return null;
    }

    public static function maskedDetails(ReferralPayout $payout): string
    {
        return PaymentDetails::mask($payout->payment_details) ?? 'No payment details';
    }
}
