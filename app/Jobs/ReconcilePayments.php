<?php

namespace App\Jobs;

use App\Domain\Orders\OrderStateMachine;
use App\Domain\Payments\PaymentConfirmationService;
use App\Domain\Payments\Paystack\PaystackException;
use App\Domain\Pricing\CouponReservations;
use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for missed webhooks: re-verifies open payments with Paystack and
 * expires payment sessions that were never completed (releasing their coupon
 * reservations).
 */
class ReconcilePayments implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('payments');
    }

    public function handle(PaymentConfirmationService $confirmations, OrderStateMachine $states, CouponReservations $reservations): void
    {
        Payment::query()
            ->where('status', PaymentRecordStatus::Initialized->value)
            ->where('provider', 'paystack')
            ->whereNotNull('authorization_url')
            ->where('created_at', '<', now()->subMinutes(10))
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (Payment $payment) use ($confirmations, $states, $reservations) {
                try {
                    $outcome = $confirmations->confirmByReference($payment->reference, 'reconcile');
                } catch (PaystackException $e) {
                    Log::info('Reconcile: verify failed', ['reference' => $payment->reference, 'error' => $e->getMessage()]);
                    $outcome = null;
                }

                $payment->refresh();
                if ($payment->status === PaymentRecordStatus::Initialized && $payment->expires_at && $payment->expires_at->isPast()) {
                    $payment->forceFill(['status' => PaymentRecordStatus::Abandoned, 'failure_reason' => 'expired'])->save();

                    $order = $payment->order;
                    if ($payment->purpose === 'order' && $order && $order->payment_reference === $payment->reference && $order->payment_status !== PaymentStatus::Paid) {
                        $reservations->release($order);
                        $order->forceFill(['payment_status' => PaymentStatus::Unpaid])->save();
                        $states->transitionIfAllowed($order, OrderStatus::PaymentExpired, 'system', 'Payment session expired');
                    }
                }
            });
    }
}
