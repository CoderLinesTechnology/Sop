<?php

namespace App\Domain\Payments;

use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Email\OrderEmailVariables;
use App\Domain\Email\TransactionalMailer;
use App\Domain\Notifications\AdminNotifier;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Payments\Paystack\PaystackGateway;
use App\Domain\Pricing\CouponReservations;
use App\Enums\EmailTemplateKey;
use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\RevisionStatus;
use App\Models\AnalyticsEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\Analytics;
use App\Support\Audit;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only code path that marks an order as PAID.
 *
 * Whatever triggered it (webhook, customer returning from Paystack, the
 * reconciliation job), the transaction is re-verified server-to-server with
 * Paystack and applied under row locks:
 *
 *   verified.status === "success"
 *   AND verified.amount === payment.amount === order.total_amount
 *   AND verified.currency === payment.currency === order.currency
 *   AND verified.reference === payment.reference === order.payment_reference
 *
 * Re-running it (duplicate or replayed webhooks, double callbacks) is a no-op,
 * and the AI job is created with a unique dedupe key, so fulfilment can never
 * start twice.
 */
class PaymentConfirmationService
{
    public function __construct(
        private readonly PaystackGateway $paystack,
        private readonly OrderStateMachine $states,
        private readonly CouponReservations $reservations,
        private readonly TransactionalMailer $mailer,
        private readonly AdminNotifier $notifier,
    ) {}

    /** @throws Paystack\PaystackException when Paystack cannot be reached (caller retries) */
    public function confirmByReference(string $reference, string $source): ConfirmationOutcome
    {
        $payment = Payment::query()->where('reference', $reference)->first();
        if (! $payment) {
            SecurityLog::record('unknown_payment_reference', 'medium', ['reference' => mb_substr($reference, 0, 100), 'source' => $source]);

            return ConfirmationOutcome::NotFound;
        }

        if ($this->isSettled($payment)) {
            return ConfirmationOutcome::AlreadyConfirmed;
        }

        $verified = $this->paystack->verify($payment->reference);

        return $this->applyVerification($payment, $verified, $source);
    }

    /** @param array<string, mixed> $data the verify API "data" object */
    public function applyVerification(Payment $payment, array $data, string $source): ConfirmationOutcome
    {
        $afterCommit = [];

        $outcome = DB::transaction(function () use ($payment, $data, $source, &$afterCommit) {
            /** @var Payment $payment */
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($this->isSettled($payment)) {
                return ConfirmationOutcome::AlreadyConfirmed;
            }

            /** @var Order $order */
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $status = strtolower((string) ($data['status'] ?? ''));

            if ($status !== 'success') {
                return $this->handleUnsuccessful($payment, $order, $status, $data, $afterCommit);
            }

            $mismatches = $this->mismatches($payment, $order, $data);
            if ($mismatches !== []) {
                $payment->forceFill([
                    'status' => PaymentRecordStatus::Mismatch,
                    'mismatch_details' => $mismatches,
                    'verification_data' => $this->sanitize($data),
                    'verified_at' => now(),
                ])->save();

                $this->states->transitionIfAllowed($order, OrderStatus::ManualReview, 'system', 'Payment verification mismatch', ['mismatches' => array_keys($mismatches)]);

                $afterCommit[] = function () use ($order, $mismatches, $source) {
                    SecurityLog::record('payment_mismatch', 'high', ['order' => $order->reference, 'source' => $source, 'mismatches' => $mismatches], $order);
                    $this->notifier->manualReviewRequired($order, 'Payment verification mismatch: '.implode(', ', array_keys($mismatches)).'. Do not fulfil until reviewed.');
                };

                return ConfirmationOutcome::Mismatch;
            }

            $payment->forceFill([
                'status' => PaymentRecordStatus::Success,
                'paid_at' => isset($data['paid_at']) ? \Illuminate\Support\Carbon::parse($data['paid_at']) : now(),
                'verified_at' => now(),
                'provider_transaction_id' => isset($data['id']) ? (string) $data['id'] : null,
                'channel' => isset($data['channel']) ? mb_substr((string) $data['channel'], 0, 40) : null,
                'gateway_response' => isset($data['gateway_response']) ? mb_substr((string) $data['gateway_response'], 0, 250) : null,
                'fees' => isset($data['fees']) ? (int) $data['fees'] : null,
                'verification_data' => $this->sanitize($data),
            ])->save();

            if ($payment->purpose === 'revision') {
                $this->markRevisionPaid($payment, $afterCommit);

                return ConfirmationOutcome::Confirmed;
            }

            $this->markOrderPaid($order, $source, $afterCommit);

            return ConfirmationOutcome::Confirmed;
        });

        foreach ($afterCommit as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                Log::error('Post-payment step failed', ['payment' => $payment->reference, 'error' => $e->getMessage()]);
            }
        }

        return $outcome;
    }

    /** A fully discounted order: no gateway involved, still a recorded, audited "payment". */
    public function confirmWaived(Payment $payment): ConfirmationOutcome
    {
        $afterCommit = [];

        $outcome = DB::transaction(function () use ($payment, &$afterCommit) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($this->isSettled($payment)) {
                return ConfirmationOutcome::AlreadyConfirmed;
            }

            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            if ($payment->amount !== 0 || $order->total_amount !== 0 || $order->payment_reference !== $payment->reference) {
                SecurityLog::record('waiver_rejected', 'high', ['order' => $order->reference], $order);

                return ConfirmationOutcome::Mismatch;
            }

            $payment->forceFill(['status' => PaymentRecordStatus::Waived, 'paid_at' => now(), 'verified_at' => now()])->save();
            Audit::log('payment.waived', $order, meta: ['coupon' => $order->coupon_code, 'promotion_id' => $order->promotion_id]);
            $this->markOrderPaid($order, 'waiver', $afterCommit);

            return ConfirmationOutcome::Confirmed;
        });

        foreach ($afterCommit as $callback) {
            $callback();
        }

        return $outcome;
    }

    private function markOrderPaid(Order $order, string $source, array &$afterCommit): void
    {
        $order->forceFill([
            'payment_status' => PaymentStatus::Paid,
            'estimated_ready_at' => now()->addMinutes($order->service?->deliveryWindow()[1] ?? 30),
        ])->save();

        if ($order->status !== OrderStatus::PaymentConfirmed) {
            $this->states->transition($order, OrderStatus::PaymentConfirmed, 'system', reason: "Payment verified ({$source})");
        }

        $this->reservations->redeem($order);

        if ($order->create_account) {
            $user = User::query()->firstOrCreate(['email' => $order->email], ['name' => $order->customer_name]);
            $order->forceFill(['user_id' => $user->id])->save();
        }

        $afterCommit[] = function () use ($order) {
            app(PipelineDispatcher::class)->startForOrder($order);

            $this->mailer->send(EmailTemplateKey::PaymentReceived, $order->email, OrderEmailVariables::for($order), $order);
            $this->notifier->paymentSucceeded($order);
            Analytics::recordServer(AnalyticsEvent::PAYMENT_SUCCESS, ['service_id' => $order->service_id, 'order_id' => $order->id, 'value' => $order->total_amount]);
        };
    }

    private function markRevisionPaid(Payment $payment, array &$afterCommit): void
    {
        $revision = $payment->revision;
        if (! $revision || $revision->status !== RevisionStatus::AwaitingPayment) {
            return;
        }

        $revision->forceFill(['status' => RevisionStatus::Requested])->save();
        $afterCommit[] = fn () => app(\App\Domain\Orders\RevisionService::class)->begin($revision->refresh());
    }

    private function handleUnsuccessful(Payment $payment, Order $order, string $status, array $data, array &$afterCommit): ConfirmationOutcome
    {
        if (in_array($status, ['ongoing', 'pending', 'processing', 'queued', ''], true)) {
            return ConfirmationOutcome::Pending;
        }

        $payment->forceFill([
            'status' => $status === 'abandoned' ? PaymentRecordStatus::Abandoned : ($status === 'reversed' ? PaymentRecordStatus::Reversed : PaymentRecordStatus::Failed),
            'gateway_response' => isset($data['gateway_response']) ? mb_substr((string) $data['gateway_response'], 0, 250) : null,
            'failure_reason' => $status,
            'verification_data' => $this->sanitize($data),
            'verified_at' => now(),
        ])->save();

        if ($payment->purpose === 'order' && $order->payment_reference === $payment->reference && $order->payment_status !== PaymentStatus::Paid) {
            $order->forceFill(['payment_status' => PaymentStatus::Failed])->save();
            $this->reservations->release($order);
            $this->states->transitionIfAllowed($order, OrderStatus::PaymentFailed, 'system', 'Payment '.$status);
            $afterCommit[] = fn () => Analytics::recordServer(AnalyticsEvent::PAYMENT_FAILED, ['service_id' => $order->service_id, 'order_id' => $order->id]);
        }

        return ConfirmationOutcome::Failed;
    }

    /** @return array<string, array{expected:mixed, received:mixed}> */
    private function mismatches(Payment $payment, Order $order, array $data): array
    {
        $issues = [];
        $amount = isset($data['amount']) ? (int) $data['amount'] : null;
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        $reference = (string) ($data['reference'] ?? '');

        if ($amount !== $payment->amount) {
            $issues['amount'] = ['expected' => $payment->amount, 'received' => $amount];
        }
        if ($currency !== strtoupper($payment->currency)) {
            $issues['currency'] = ['expected' => $payment->currency, 'received' => $currency];
        }
        if (! hash_equals($payment->reference, $reference)) {
            $issues['reference'] = ['expected' => $payment->reference, 'received' => mb_substr($reference, 0, 100)];
        }

        if ($payment->purpose === 'order') {
            if ($order->payment_reference !== $payment->reference) {
                $issues['order_reference'] = ['expected' => $order->payment_reference, 'received' => $payment->reference];
            }
            if ($order->total_amount !== $payment->amount || strtoupper($order->currency) !== strtoupper($payment->currency)) {
                $issues['order_total'] = ['expected' => $order->total_amount.' '.$order->currency, 'received' => $payment->amount.' '.$payment->currency];
            }
            if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)) {
                $issues['order_status'] = ['expected' => 'payable', 'received' => $order->status->value];
            }
        }

        return $issues;
    }

    private function isSettled(Payment $payment): bool
    {
        return in_array($payment->status, [
            PaymentRecordStatus::Success, PaymentRecordStatus::Waived, PaymentRecordStatus::Refunded,
            PaymentRecordStatus::PartiallyRefunded, PaymentRecordStatus::Mismatch,
        ], true);
    }

    /** Keep only what is useful for reconciliation; never store full card or authorization data. */
    private function sanitize(array $data): array
    {
        return array_filter([
            'id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
            'reference' => $data['reference'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'paid_at' => $data['paid_at'] ?? null,
            'channel' => $data['channel'] ?? null,
            'gateway_response' => $data['gateway_response'] ?? null,
            'fees' => $data['fees'] ?? null,
            'customer_email' => $data['customer']['email'] ?? null,
            'card' => isset($data['authorization']) ? array_filter([
                'last4' => $data['authorization']['last4'] ?? null,
                'card_type' => $data['authorization']['card_type'] ?? null,
                'bank' => $data['authorization']['bank'] ?? null,
            ]) : null,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
