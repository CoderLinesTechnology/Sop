<?php

namespace App\Domain\Referrals\Tasks;

use App\Domain\Referrals\PartnerApplications;
use App\Domain\Referrals\ReferralProgramme;
use App\Models\PartnerApplication;
use App\Models\Referrer;

/**
 * Heartbeat task: retention for the partner programme.
 *
 *  - unconfirmed applications older than 7 days are deleted;
 *  - rejected applications are deleted referrals.rejected_application_days
 *    after the decision;
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
