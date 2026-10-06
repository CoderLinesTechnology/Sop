<?php

namespace App\Filament\Resources\Coupons\Pages;

use App\Filament\Resources\Coupons\CouponResource;
use App\Filament\Resources\Coupons\Schemas\CouponForm;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\AuditSnapshot;
use App\Support\Audit;
use Filament\Resources\Pages\CreateRecord;

class CreateCoupon extends CreateRecord
{
    protected static string $resource = CouponResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = CouponForm::normalizeDiscount($data);
        $data['created_by_admin_id'] = AdminContext::user()?->id;

        return $data;
    }

    protected function afterCreate(): void
    {
        Audit::log('coupon.created', $this->getRecord(), after: AuditSnapshot::of($this->getRecord(), ['services']));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
