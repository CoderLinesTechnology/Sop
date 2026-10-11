<?php

namespace App\Domain\Referrals\Tasks;

use App\Domain\Referrals\ReferralCommissions;
use App\Enums\PaymentRecordStatus;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\ReferralCommission;
use App\Models\Refund;
use Illuminate\Database\Query\Builder;

/**
 * Heartbeat task and safety net for the commission ledger: records entries
 * that payment confirmation or a refund could not write at the time (the
 * ledger is written after those commit and never blocks them).
 *
 * Bounded: payments and refunds of the last 60 days only, 50 of each per run,
 * on indexed columns. Self-referrals (which deliberately have no entry) are
 * excluded in SQL so they cannot crowd out real gaps.
 */
class ReconcileReferralCommissions
{
    private const BATCH = 50;

    private const LOOKBACK_DAYS = 60;

    public function __invoke(ReferralCommissions $commissions): void
    {
        Payment::query()
            ->select('payments.id')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->join('referrers', 'referrers.id', '=', 'orders.referrer_id')
            ->whereIn('payments.purpose', ['order', 'revision'])
            ->whereIn('payments.status', [PaymentRecordStatus::Success->value, PaymentRecordStatus::PartiallyRefunded->value, PaymentRecordStatus::Refunded->value])
            ->where('payments.amount', '>', 0)
            ->where('payments.created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->where(fn ($query) => $query->whereNull('referrers.email')->orWhere(fn ($query) => $query
                ->whereColumn('orders.email', '!=', 'referrers.email')
                ->where(fn ($query) => $query->whereNull('payments.customer_email')->orWhereColumn('payments.customer_email', '!=', 'referrers.email'))))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('referral_commissions')
                ->whereColumn('referral_commissions.payment_id', 'payments.id'))
            ->orderBy('payments.id')
            ->limit(self::BATCH)
            ->pluck('payments.id')
            ->each(fn (int $id) => $commissions->recordForPaymentSafely($id));

        Refund::query()
            ->select('refunds.id')
            ->where('refunds.status', RefundStatus::Processed->value)
            ->where('refunds.updated_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('referral_commissions')
                ->whereColumn('referral_commissions.payment_id', 'refunds.payment_id')
                ->whereIn('referral_commissions.kind', [ReferralCommission::KIND_PAYMENT, ReferralCommission::KIND_REVISION_PAYMENT]))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('referral_commissions')
                ->whereColumn('referral_commissions.refund_id', 'refunds.id'))
            ->orderBy('refunds.id')
            ->limit(self::BATCH)
            ->pluck('refunds.id')
            ->each(fn (int $id) => $commissions->reverseForRefundSafely($id));
    }
}
