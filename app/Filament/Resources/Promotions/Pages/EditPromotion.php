<?php

namespace App\Filament\Resources\Promotions\Pages;

use App\Filament\Resources\Promotions\PromotionResource;
use App\Filament\Resources\Promotions\Tables\PromotionsTable;
use App\Filament\Support\Operations\AuditSnapshot;
use App\Filament\Support\Operations\DiscountFields;
use App\Models\Promotion;
use App\Support\Audit;
use Filament\Resources\Pages\EditRecord;

class EditPromotion extends EditRecord
{
    protected static string $resource = PromotionResource::class;

    /** @var array<string, mixed> */
    protected array $snapshotBeforeSave = [];

    protected function getHeaderActions(): array
    {
        return [
            PromotionsTable::deleteAction(),
            PromotionsTable::restoreAction(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return DiscountFields::normalize($data);
    }

    protected function beforeSave(): void
    {
        $this->snapshotBeforeSave = AuditSnapshot::of($this->getRecord(), ['services']);
    }

    protected function afterSave(): void
    {
        /** @var Promotion $promotion */
        $promotion = $this->getRecord();

        if ($promotion->applies_to_all_services) {
            $promotion->services()->detach();
        }

        [$before, $after] = AuditSnapshot::diff($this->snapshotBeforeSave, AuditSnapshot::of($promotion->refresh(), ['services']));

        if ($after !== []) {
            Audit::log('promotion.updated', $promotion, $before, $after);
        }
    }
}
