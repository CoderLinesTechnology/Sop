<?php

namespace App\Filament\Resources\Referrers;

use App\Filament\Support\Operations\AuditSnapshot;
use App\Models\Referrer;
use Illuminate\Support\Arr;

/** Audit snapshots of a referrer: every edited attribute, never the dashboard token, its hash or payment details. */
final class ReferrerAudit
{
    private const SECRET = ['dashboard_token', 'dashboard_token_hash', 'payment_details', 'pending_payment_details', 'pending_details_token_hash', 'pending_details_expires_at'];

    /** @return array<string, mixed> */
    public static function snapshot(Referrer $referrer): array
    {
        return Arr::except(AuditSnapshot::of($referrer), self::SECRET);
    }
}
