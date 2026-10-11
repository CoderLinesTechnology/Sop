<?php

namespace App\Filament\Resources\Referrers\Pages;

use App\Filament\Resources\Referrers\ReferrerActions;
use App\Filament\Resources\Referrers\ReferrerAudit;
use App\Filament\Resources\Referrers\ReferrerResource;
use App\Filament\Support\Operations\AuditSnapshot;
use App\Models\Referrer;
use App\Support\Audit;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * @property-read Referrer $record
 */
class EditReferrer extends EditRecord
{
    protected static string $resource = ReferrerResource::class;

    /** @var array<string, mixed> */
    protected array $snapshotBeforeSave = [];

    protected function getHeaderActions(): array
    {
        $refresh = fn () => $this->refreshFormData(['name', 'email', 'phone', 'notes', 'is_active', 'code']);

        return [
            ReferrerActions::shareLink(),
            ReferrerActions::paymentDetails(),
            ReferrerActions::dashboardLink(),
            ReferrerActions::sendDashboardLink(),
            ActionGroup::make([
                ReferrerActions::replaceDashboardLink(),
                ReferrerActions::disableDashboardLink(),
                ReferrerActions::deactivate()->after($refresh),
                ReferrerActions::activate()->after($refresh),
                ReferrerActions::erase()->after($refresh),
            ])->tooltip('More actions'),
        ];
    }

    protected function beforeSave(): void
    {
        /** @var Referrer $referrer */
        $referrer = $this->getRecord();

        // An erased referrer's personal data must not come back through a stale form.
        if (Referrer::withTrashed()->whereKey($referrer->getKey())->whereNotNull('erased_at')->exists()) {
            Notification::make()->danger()->title('This referrer’s personal data was erased; it can no longer be edited.')->send();
            $this->halt();
        }

        $this->snapshotBeforeSave = ReferrerAudit::snapshot($referrer);
    }

    protected function afterSave(): void
    {
        [$before, $after] = AuditSnapshot::diff($this->snapshotBeforeSave, ReferrerAudit::snapshot($this->getRecord()->refresh()));

        if ($after !== []) {
            Audit::log('referrer.updated', $this->getRecord(), $before, $after);
        }
    }
}
