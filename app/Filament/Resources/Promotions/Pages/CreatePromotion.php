<?php

namespace App\Filament\Resources\Promotions\Pages;

use App\Filament\Resources\Promotions\PromotionResource;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\AuditSnapshot;
use App\Filament\Support\Operations\DiscountFields;
use App\Support\Audit;
use Filament\Resources\Pages\CreateRecord;

class CreatePromotion extends CreateRecord
{
    protected static string $resource = PromotionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = DiscountFields::normalize($data);
        $data['created_by_admin_id'] = AdminContext::user()?->id;

        return $data;
    }

    protected function afterCreate(): void
    {
        Audit::log('promotion.created', $this->getRecord(), after: AuditSnapshot::of($this->getRecord(), ['services']));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
