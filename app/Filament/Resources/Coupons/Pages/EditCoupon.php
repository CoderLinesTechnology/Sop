<?php

namespace App\Filament\Resources\Coupons\Pages;

use App\Filament\Resources\Coupons\CouponResource;
use App\Filament\Resources\Coupons\Schemas\CouponForm;
use App\Filament\Resources\Coupons\Tables\CouponsTable;
use App\Filament\Support\Operations\AuditSnapshot;
use App\Models\Coupon;
use App\Support\Audit;
use Filament\Resources\Pages\EditRecord;

/**
 * @property-read Coupon $record
 */
class EditCoupon extends EditRecord
{
    protected static string $resource = CouponResource::class;

    /** @var array<string, mixed> */
    protected array $snapshotBeforeSave = [];

    protected function getHeaderActions(): array
    {
        return [
            CouponsTable::deleteAction(),
            CouponsTable::restoreAction(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CouponForm::normalizeDiscount($data);
    }

    protected function beforeSave(): void
    {
        $this->snapshotBeforeSave = AuditSnapshot::of($this->getRecord(), ['services']);
    }

    protected function afterSave(): void
    {
        /** @var Coupon $coupon */
        $coupon = $this->getRecord();

        if ($coupon->applies_to_all_services) {
            $coupon->services()->detach();
        }

        [$before, $after] = AuditSnapshot::diff($this->snapshotBeforeSave, AuditSnapshot::of($coupon->refresh(), ['services']));

        if ($after !== []) {
            Audit::log('coupon.updated', $coupon, $before, $after);
        }
    }
}
