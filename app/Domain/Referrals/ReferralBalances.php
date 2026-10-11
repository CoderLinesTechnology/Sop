<?php

namespace App\Domain\Referrals;

use App\Models\ReferralCommission;
use App\Models\Referrer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * A referrer's balances, computed from the ledger in one grouped query per
 * referrer (index: referral_commissions(referrer_id, …)):
 *
 *  - pending:   earnings recorded less than referrals.hold_days ago;
 *  - available: older earnings plus every refund reversal (reversals count at
 *               once), not paid and not reserved in an open payout request;
 *  - requested: reserved in an open payout request;
 *  - paid:      paid out.
 *
 * A reversal recorded after a request is not part of it, so it lowers the
 * next available balance, which can go below zero.
 */
final class ReferralBalances
{
    /**
     * @return list<array{currency:string, revenue:int, earned:int, pending:int, available:int, requested:int, paid:int, next_available_at:?Carbon}>
     */
    public function forReferrer(Referrer $referrer): array
    {
        $holdDays = ReferralProgramme::holdDays();
        $cutoff = now()->subDays($holdDays)->format('Y-m-d H:i:s');
        $reversal = ReferralCommission::KIND_REFUND_REVERSAL;
        $open = 'paid_at IS NULL AND payout_id IS NULL';
        $held = 'kind <> ? AND created_at > ?';

        return ReferralCommission::query()
            ->where('referrer_id', $referrer->id)
            ->groupBy('currency')
            ->orderBy('currency')
            ->selectRaw(
                'currency, SUM(base_amount) AS revenue, SUM(amount) AS earned, '
                ."SUM(CASE WHEN {$open} AND {$held} THEN amount ELSE 0 END) AS pending, "
                ."SUM(CASE WHEN {$open} AND NOT ({$held}) THEN amount ELSE 0 END) AS available, "
                .'SUM(CASE WHEN paid_at IS NULL AND payout_id IS NOT NULL THEN amount ELSE 0 END) AS requested, '
                .'SUM(CASE WHEN paid_at IS NOT NULL THEN amount ELSE 0 END) AS paid, '
                ."MIN(CASE WHEN {$open} AND {$held} THEN created_at END) AS next_pending_at",
                [$reversal, $cutoff, $reversal, $cutoff, $reversal, $cutoff],
            )
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'currency' => (string) $row->currency,
                'revenue' => (int) $row->revenue,
                'earned' => (int) $row->earned,
                'pending' => (int) $row->pending,
                'available' => (int) $row->available,
                'requested' => (int) $row->requested,
                'paid' => (int) $row->paid,
                'next_available_at' => $row->next_pending_at ? Carbon::parse($row->next_pending_at)->addDays($holdDays) : null,
            ])
            ->values()
            ->all();
    }

    /** The balances in one currency (zeros when there is nothing in it yet). */
    public function inCurrency(Referrer $referrer, string $currency): array
    {
        foreach ($this->forReferrer($referrer) as $row) {
            if ($row['currency'] === strtoupper($currency)) {
                return $row;
            }
        }

        return ['currency' => strtoupper($currency), 'revenue' => 0, 'earned' => 0, 'pending' => 0, 'available' => 0, 'requested' => 0, 'paid' => 0, 'next_available_at' => null];
    }

    /**
     * The ledger entries an available balance is made of (for linking to a
     * payout request). Call inside a transaction: the rows are locked.
     *
     * @return Collection<int, ReferralCommission>
     */
    public function availableEntriesForUpdate(Referrer $referrer, string $currency): Collection
    {
        $cutoff = now()->subDays(ReferralProgramme::holdDays());

        return ReferralCommission::query()
            ->where('referrer_id', $referrer->id)
            ->where('currency', strtoupper($currency))
            ->whereNull('paid_at')
            ->whereNull('payout_id')
            ->where(fn ($query) => $query
                ->where('kind', ReferralCommission::KIND_REFUND_REVERSAL)
                ->orWhere('created_at', '<=', $cutoff))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'amount']);
    }
}
