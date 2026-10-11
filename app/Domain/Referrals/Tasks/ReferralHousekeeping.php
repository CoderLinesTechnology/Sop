<?php

namespace App\Domain\Referrals\Tasks;

use App\Domain\Referrals\PartnerApplications;
use App\Domain\Referrals\ReferralProgramme;
use App\Domain\Referrals\ReferrerService;
use App\Models\PartnerApplication;
use App\Models\ReferralPayout;
use App\Models\Referrer;
use Throwable;

/**
 * Heartbeat task: retention for the partner programme.
 *
 *  - unconfirmed applications older than 7 days are deleted;
 *  - rejected applications are deleted referrals.rejected_application_days
 *    after the decision, approved ones referrals.approved_application_days
 *    after it (the partner record holds what the programme needs);
 *  - money records keep personal data for referrals.financial_record_years
 *    (accounting and tax): closed payouts older than that lose the payment
 *    details they were sent to, and a partner deactivated that long ago with
 *    no ledger or payout activity since is erased (amounts stay, anonymous);
 *  - payment-detail changes that were never confirmed are cleared once
 *    their 48-hour link has expired.
 *
 * Bounded: at most 500 rows per kind per run, on indexed columns.
 */
class ReferralHousekeeping
{
    private const BATCH = 500;

    public function __invoke(): void
    {
        $this->deleteIn(PartnerApplication::query()
            ->where('status', PartnerApplication::STATUS_UNCONFIRMED)
            ->where('created_at', '<', now()->subDays(PartnerApplications::UNCONFIRMED_DAYS)));

        $this->deleteIn(PartnerApplication::query()
            ->where('status', PartnerApplication::STATUS_REJECTED)
            ->where('decided_at', '<', now()->subDays(ReferralProgramme::rejectedApplicationDays())));

        $this->deleteIn(PartnerApplication::query()
            ->where('status', PartnerApplication::STATUS_APPROVED)
            ->where('decided_at', '<', now()->subDays(ReferralProgramme::approvedApplicationDays())));

        $cutoff = now()->subYears(ReferralProgramme::financialRecordYears());

        ReferralPayout::query()
            ->whereIn('status', [ReferralPayout::STATUS_PAID, ReferralPayout::STATUS_REJECTED, ReferralPayout::STATUS_CANCELLED])
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('payment_details')
            ->limit(self::BATCH)
            ->update(['payment_details' => null, 'updated_at' => now()]);

        $ended = Referrer::withTrashed()
            ->whereNull('erased_at')
            ->where('is_active', false)
            ->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('commissions', fn ($q) => $q->where('created_at', '>=', $cutoff))
            ->whereDoesntHave('payouts', fn ($q) => $q->where(fn ($w) => $w->where('created_at', '>=', $cutoff)->orWhere('status', ReferralPayout::STATUS_REQUESTED)))
            ->orderBy('id')
            ->limit(50)
            ->get();
        foreach ($ended as $referrer) {
            try {
                app(ReferrerService::class)->erase($referrer, null);
            } catch (Throwable $e) {
                report($e);
            }
        }

        Referrer::withTrashed()
            ->whereNotNull('pending_details_expires_at')
            ->where('pending_details_expires_at', '<', now())
            ->limit(self::BATCH)
            ->update([
                'pending_payment_details' => null,
                'pending_details_token_hash' => null,
                'pending_details_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function deleteIn($query): void
    {
        $ids = $query->orderBy('id')->limit(self::BATCH)->pluck('id');
        if ($ids->isNotEmpty()) {
            PartnerApplication::query()->whereKey($ids->all())->delete();
        }
    }
}
