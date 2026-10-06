<?php

namespace App\Domain\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Audit;

/**
 * Server-side authorization for fulfilment. Nothing the browser does can make
 * an order fulfillable: the order must be PAID, the verified payment must match
 * the expected amount, currency and reference exactly, and fulfilment can be
 * claimed only once (atomic UPDATE ... WHERE fulfillment_started_at IS NULL).
 */
final class FulfillmentGuard
{
    /**
     * Verify payment and atomically claim the right to start fulfilment.
     *
     * @throws FulfillmentDenied
     */
    public function claim(Order $order): Payment
    {
        $payment = $this->verifiedPayment($order);

        $claimed = Order::query()
            ->whereKey($order->getKey())
            ->whereNull('fulfillment_started_at')
            ->update(['fulfillment_started_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            throw new FulfillmentDenied('Fulfilment has already started for this order.', 'already_started');
        }

        $order->fulfillment_started_at = now();

        return $payment;
    }

    /**
     * Checks that must hold before every pipeline stage after the claim.
     *
     * @throws FulfillmentDenied
     */
    public function assertStillFulfillable(Order $order): void
    {
        $order->refresh();

        if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)) {
            throw new FulfillmentDenied('Order is '.$order->status->value.'.', 'order_closed');
        }

        if ($order->fulfillment_started_at === null) {
            throw new FulfillmentDenied('Fulfilment was never claimed for this order.', 'not_claimed');
        }

        $this->verifiedPayment($order);
    }

    /** @throws FulfillmentDenied */
    public function verifiedPayment(Order $order): Payment
    {
        if ($order->payment_status !== PaymentStatus::Paid) {
            throw new FulfillmentDenied('Order is not paid.', 'not_paid');
        }

        /** @var Payment|null $payment */
        $payment = $order->payments()
            ->where('purpose', 'order')
            ->where('reference', $order->payment_reference)
            ->first();

        if (! $payment) {
            $this->flag($order, 'payment_missing');
            throw new FulfillmentDenied('No payment record matches the order reference.', 'payment_missing');
        }

        $waived = $payment->status === PaymentRecordStatus::Waived && $order->total_amount === 0 && $payment->amount === 0;
        $verified = $payment->status === PaymentRecordStatus::Success && $payment->verified_at !== null;

        if (! $waived && ! $verified) {
            $this->flag($order, 'payment_not_verified');
            throw new FulfillmentDenied('Payment has not been verified.', 'payment_not_verified');
        }

        if ($payment->amount !== $order->total_amount
            || strtoupper($payment->currency) !== strtoupper($order->currency)
            || $payment->reference !== $order->payment_reference) {
            $this->flag($order, 'payment_mismatch');
            throw new FulfillmentDenied('Verified payment does not match the order.', 'payment_mismatch');
        }

        return $payment;
    }

    private function flag(Order $order, string $code): void
    {
        Audit::log('fulfillment.denied', $order, meta: ['code' => $code]);
    }
}
