<?php

namespace App\Domain\Payments;

use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Email\OrderEmailVariables;
use App\Domain\Email\TransactionalMailer;
use App\Domain\Notifications\AdminNotifier;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Payments\Paystack\PaystackException;
use App\Domain\Payments\Paystack\PaystackGateway;
use App\Enums\AiJobStatus;
use App\Enums\EmailTemplateKey;
use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Refund workflow: requested → approved → processing (Paystack) → processed.
 *
 * Duplicate protection: only one open refund per payment at a time, amounts
 * are checked against the remaining refundable balance under a row lock, and
 * every refund carries a unique idempotency key.
 */
class RefundService
{
    public function __construct(
        private readonly PaystackGateway $paystack,
        private readonly OrderStateMachine $states,
        private readonly TransactionalMailer $mailer,
        private readonly AdminNotifier $notifier,
    ) {}

    public function request(Order $order, int $amount, string $reason, AdminUser $admin, string $requestedBy = 'admin'): Refund
    {
        $refund = DB::transaction(function () use ($order, $amount, $reason, $admin, $requestedBy) {
            /** @var Payment|null $payment */
            $payment = $order->payments()
                ->where('purpose', 'order')
                ->whereIn('status', [PaymentRecordStatus::Success->value, PaymentRecordStatus::PartiallyRefunded->value])
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $payment) {
                throw new RuntimeException('This order has no captured payment to refund.');
            }

            $open = $payment->refunds()->whereIn('status', [RefundStatus::Requested->value, RefundStatus::Approved->value, RefundStatus::Processing->value])->exists();
            if ($open) {
                throw new RuntimeException('A refund for this payment is already in progress.');
            }

            $refundable = $payment->refundableAmount();
            if ($amount <= 0 || $amount > $refundable) {
                throw new RuntimeException('Refund amount must be between '.Money::format(1, $payment->currency).' and '.Money::format($refundable, $payment->currency).'.');
            }

            return Refund::query()->create([
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'amount' => $amount,
                'currency' => $payment->currency,
                'reason' => mb_substr(trim($reason), 0, 2000),
                'status' => RefundStatus::Requested,
                'requested_by' => $requestedBy,
                'requested_by_admin_id' => $admin->id,
                'idempotency_key' => hash('sha256', $payment->id.'|'.$amount.'|'.microtime(true).'|'.bin2hex(random_bytes(8))),
            ]);
        });

        Audit::log('refund.requested', $order, after: ['amount' => $amount, 'reason' => $reason, 'refund_id' => $refund->id], admin: $admin);
        $this->notifier->refundRequested($order, Money::format($amount, $refund->currency), $reason);

        return $refund;
    }

    public function approve(Refund $refund, AdminUser $admin): Refund
    {
        $updated = Refund::query()
            ->whereKey($refund->id)
            ->where('status', RefundStatus::Requested->value)
            ->update(['status' => RefundStatus::Approved->value, 'approved_by_admin_id' => $admin->id, 'updated_at' => now()]);

        if ($updated !== 1) {
            throw new RuntimeException('Only requested refunds can be approved.');
        }

        Audit::log('refund.approved', $refund->order, meta: ['refund_id' => $refund->id, 'amount' => $refund->amount], admin: $admin);

        return $this->process($refund->refresh());
    }

    public function reject(Refund $refund, AdminUser $admin, string $reason): Refund
    {
        $updated = Refund::query()
            ->whereKey($refund->id)
            ->where('status', RefundStatus::Requested->value)
            ->update(['status' => RefundStatus::Rejected->value, 'notes' => mb_substr($reason, 0, 2000), 'updated_at' => now()]);

        if ($updated !== 1) {
            throw new RuntimeException('Only requested refunds can be rejected.');
        }

        Audit::log('refund.rejected', $refund->order, meta: ['refund_id' => $refund->id, 'reason' => $reason], admin: $admin);

        return $refund->refresh();
    }

    /** Submit an approved refund to Paystack. */
    public function process(Refund $refund): Refund
    {
        $claimed = Refund::query()
            ->whereKey($refund->id)
            ->where('status', RefundStatus::Approved->value)
            ->update(['status' => RefundStatus::Processing->value, 'updated_at' => now()]);

        if ($claimed !== 1) {
            return $refund->refresh();
        }

        $refund->refresh()->load('payment');

        try {
            $data = $this->paystack->refund($refund->payment->reference, $refund->amount, $refund->currency, 'Statementra refund '.$refund->order->reference);
        } catch (PaystackException $e) {
            Log::error('Paystack refund failed', ['refund' => $refund->id, 'error' => $e->getMessage()]);
            $refund->forceFill(['status' => RefundStatus::Failed, 'failure_reason' => mb_substr($e->getMessage(), 0, 250)])->save();

            return $refund;
        }

        $refund->forceFill([
            'provider_refund_id' => isset($data['id']) ? (string) $data['id'] : null,
            'provider_status' => $data['status'] ?? null,
        ])->save();

        if (in_array(strtolower((string) ($data['status'] ?? '')), ['processed', 'success'], true)) {
            $this->finalize($refund);
        }

        return $refund->refresh();
    }

    /** Record a refund completed outside Paystack (e.g. bank transfer). */
    public function markProcessedManually(Refund $refund, AdminUser $admin, ?string $externalReference): Refund
    {
        if (! in_array($refund->status, [RefundStatus::Approved, RefundStatus::Processing, RefundStatus::Failed], true)) {
            throw new RuntimeException('Only approved, processing or failed refunds can be marked as processed.');
        }

        $refund->forceFill(['provider_refund_id' => $externalReference ?: $refund->provider_refund_id, 'provider_status' => 'manual'])->save();
        Audit::log('refund.marked_processed', $refund->order, meta: ['refund_id' => $refund->id, 'external_reference' => $externalReference], admin: $admin);

        $this->finalize($refund);

        return $refund->refresh();
    }

    /** Apply a Paystack refund.* webhook (data from the event payload). */
    public function applyProviderUpdate(string $event, array $data): void
    {
        $providerId = isset($data['id']) ? (string) $data['id'] : null;
        $reference = (string) ($data['transaction_reference'] ?? data_get($data, 'transaction.reference', ''));

        $refund = Refund::query()
            ->when($providerId, fn ($q) => $q->where('provider_refund_id', $providerId))
            ->when(! $providerId && $reference, fn ($q) => $q->whereHas('payment', fn ($p) => $p->where('reference', $reference))->where('status', RefundStatus::Processing->value))
            ->latest('id')
            ->first();

        if (! $refund) {
            Log::warning('Refund webhook did not match a refund', ['event' => $event, 'provider_id' => $providerId]);

            return;
        }

        $refund->forceFill(['provider_status' => mb_substr((string) ($data['status'] ?? $event), 0, 40)])->save();

        if ($event === 'refund.processed') {
            $this->finalize($refund);
        } elseif ($event === 'refund.failed') {
            $refund->forceFill(['status' => RefundStatus::Failed, 'failure_reason' => 'Paystack reported failure'])->save();
            $this->notifier->notify('refund_failed', 'Refund failed · '.$refund->order->reference, 'Paystack reported that the refund failed.', $refund->order, 'danger');
        }
    }

    private function finalize(Refund $refund): void
    {
        $alreadyProcessed = false;

        DB::transaction(function () use ($refund, &$alreadyProcessed) {
            $refund = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($refund->status === RefundStatus::Processed) {
                $alreadyProcessed = true;

                return;
            }

            $refund->forceFill(['status' => RefundStatus::Processed, 'processed_at' => now()])->save();

            $payment = Payment::query()->whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();
            $refunded = (int) $payment->refunds()->where('status', RefundStatus::Processed->value)->sum('amount');
            $full = $refunded >= $payment->amount;

            $payment->forceFill(['status' => $full ? PaymentRecordStatus::Refunded : PaymentRecordStatus::PartiallyRefunded])->save();

            $order = Order::query()->whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
            $order->forceFill(['payment_status' => $full ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded])->save();
            $this->states->transitionIfAllowed($order, $full ? OrderStatus::Refunded : OrderStatus::PartiallyRefunded, 'system', 'Refund processed');
        });

        if ($alreadyProcessed) {
            return;
        }

        $order = $refund->order->refresh();
        if ($order->status === OrderStatus::Refunded && $order->latestAiJob && ! in_array($order->latestAiJob->status, [AiJobStatus::Completed, AiJobStatus::Cancelled], true)) {
            app(PipelineDispatcher::class)->cancel($order, null, 'Order refunded');
        }

        $this->mailer->send(EmailTemplateKey::RefundProcessed, $order->email, OrderEmailVariables::for($order) + [
            'refund_amount' => Money::format($refund->amount, $refund->currency),
        ], $order);
    }
}
