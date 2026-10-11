<?php

namespace App\Domain\Referrals;

use App\Enums\PaymentRecordStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ReferralCommission;
use App\Models\Referrer;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The referral commission ledger (integer minor units, insert-only).
 *
 * Earnings: every confirmed payment on an attributed order (the order payment
 * and any paid revision fee) earns floor(amount × rate_bp / 10 000), with the
 * referrer's rate at the time of payment snapshotted on the entry. The base is
 * what the customer actually paid after any discount (not net of gateway fees).
 *
 * Reversals: a processed refund adds a negative entry so that, after all
 * refunds of a payment, the referrer keeps exactly
 * floor((amount − refunded) × rate_bp / 10 000) — the same floor as for
 * earnings, applied to the money the business kept. A full refund therefore
 * reverses the whole commission, and partial refunds can never over-reverse.
 *
 * Idempotency: unique keys on (payment_id, kind) and (refund_id, kind); a
 * replay (webhook + callback, heartbeat retry) returns the existing entry.
 * Self-referrals (order email = referrer email) earn nothing and get no entry.
 * Entries are paid out only through payout requests (ReferralPayouts).
 *
 * The *Safely() entry points are what payment confirmation and refunds call:
 * they never throw, so a ledger problem can never break or roll back a payment
 * or a refund. Anything missed is picked up by the ReconcileReferralCommissions
 * heartbeat task.
 */
final class ReferralCommissions
{
    private const EARNING_KINDS = [ReferralCommission::KIND_PAYMENT, ReferralCommission::KIND_REVISION_PAYMENT];

    /** Floor of amount × rate (basis points); amount and rate are non-negative. */
    public static function commissionFor(int $amount, int $rateBp): int
    {
        return intdiv(max(0, $amount) * max(0, $rateBp), 10000);
    }

    public function recordForPaymentSafely(int $paymentId): void
    {
        try {
            $payment = Payment::query()->find($paymentId);
            if ($payment) {
                $this->recordForPayment($payment);
            }
        } catch (Throwable $e) {
            report($e);
            Log::error('Referral commission not recorded', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
        }
    }

    public function reverseForRefundSafely(int $refundId): void
    {
        try {
            $refund = Refund::query()->find($refundId);
            if ($refund) {
                $this->reverseForRefund($refund);
            }
        } catch (Throwable $e) {
            report($e);
            Log::error('Referral commission reversal not recorded', ['refund_id' => $refundId, 'error' => $e->getMessage()]);
        }
    }

    /** The ledger entry for a confirmed payment on a referred order, or null when it earns nothing. */
    public function recordForPayment(Payment $payment): ?ReferralCommission
    {
        $payment = Payment::query()->find($payment->getKey());
        if (! $payment || $payment->amount <= 0 || ! in_array($payment->purpose, ['order', 'revision'], true)) {
            return null;
        }

        if (! in_array($payment->status, [PaymentRecordStatus::Success, PaymentRecordStatus::PartiallyRefunded, PaymentRecordStatus::Refunded], true)) {
            return null;
        }

        $order = Order::query()->select(['id', 'reference', 'email', 'referrer_id'])->find($payment->order_id);
        $referrer = $order?->referrer_id ? Referrer::withTrashed()->find($order->referrer_id) : null;
        if (! $order || ! $referrer) {
            return null;
        }

        if (self::isSelfReferral($order->email, $referrer) || self::isSelfReferral($payment->customer_email, $referrer)) {
            Log::info('Self-referral: no commission', ['order' => $order->reference]);

            return null;
        }

        $rate = (int) $referrer->commission_rate_bp;
        $entry = ReferralCommission::query()->createOrFirst(
            [
                'payment_id' => $payment->id,
                'kind' => $payment->purpose === 'revision' ? ReferralCommission::KIND_REVISION_PAYMENT : ReferralCommission::KIND_PAYMENT,
            ],
            [
                'referrer_id' => $referrer->id,
                'order_id' => $order->id,
                'base_amount' => (int) $payment->amount,
                'currency' => strtoupper((string) $payment->currency),
                'rate_bp' => $rate,
                'amount' => self::commissionFor((int) $payment->amount, $rate),
            ],
        );

        // A refund processed before this entry existed (e.g. a retried entry) still gets its reversal.
        Refund::query()
            ->where('payment_id', $payment->id)
            ->where('status', RefundStatus::Processed->value)
            ->whereNotIn('id', ReferralCommission::query()->select('refund_id')->whereNotNull('refund_id'))
            ->orderBy('id')
            ->get()
            ->each(fn (Refund $refund) => $this->reverseForRefund($refund));

        return $entry;
    }

    /** The negative entry for a processed refund of a commissioned payment, or null. */
    public function reverseForRefund(Refund $refund): ?ReferralCommission
    {
        return DB::transaction(function () use ($refund) {
            $refund = Refund::query()->find($refund->getKey());
            if (! $refund || $refund->status !== RefundStatus::Processed || $refund->amount <= 0) {
                return null;
            }

            // Locking the earning entry serialises reversals of the same payment.
            $earning = ReferralCommission::query()
                ->where('payment_id', $refund->payment_id)
                ->whereIn('kind', self::EARNING_KINDS)
                ->lockForUpdate()
                ->first();
            if (! $earning) {
                return null;
            }

            $existing = ReferralCommission::query()
                ->where('refund_id', $refund->id)
                ->where('kind', ReferralCommission::KIND_REFUND_REVERSAL)
                ->first();
            if ($existing) {
                return $existing;
            }

            $before = ReferralCommission::query()
                ->where('kind', ReferralCommission::KIND_REFUND_REVERSAL)
                ->whereIn('refund_id', Refund::query()->select('id')->where('payment_id', $refund->payment_id))
                ->selectRaw('COALESCE(SUM(base_amount), 0) AS base_total, COALESCE(SUM(amount), 0) AS amount_total')
                ->first();
            $refundedBefore = -(int) $before?->base_total;
            $reversedBefore = -(int) $before?->amount_total;

            $refunded = min($refund->amount, max(0, $earning->base_amount - $refundedBefore));
            $kept = self::commissionFor($earning->base_amount - $refundedBefore - $refunded, $earning->rate_bp);
            $reversal = max(0, $earning->amount - $reversedBefore - $kept);

            return ReferralCommission::query()->createOrFirst(
                ['refund_id' => $refund->id, 'kind' => ReferralCommission::KIND_REFUND_REVERSAL],
                [
                    'referrer_id' => $earning->referrer_id,
                    'order_id' => $earning->order_id,
                    'base_amount' => -$refunded,
                    'currency' => $earning->currency,
                    'rate_bp' => $earning->rate_bp,
                    'amount' => -$reversal,
                ],
            );
        });
    }

    public static function isSelfReferral(?string $email, Referrer $referrer): bool
    {
        $email = strtolower(trim((string) $email));

        return $email !== '' && $referrer->email !== null && hash_equals(strtolower($referrer->email), $email);
    }
}
