<?php

namespace App\Domain\Payments;

use App\Domain\Orders\OrderStateMachine;
use App\Domain\Payments\Paystack\PaystackException;
use App\Domain\Payments\Paystack\PaystackGateway;
use App\Domain\Pricing\CouponReservations;
use App\Domain\Pricing\PriceCalculator;
use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Models\AnalyticsEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Analytics;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Starts payment for a draft order.
 *
 * The price is recalculated here, on the server, from the service, the
 * running promotion and the coupon; nothing the browser sends can change it.
 * The coupon use is reserved under a row lock, a payment record with a fresh
 * unique reference is created, and only then is Paystack initialised. The
 * browser receives nothing but the Paystack authorization URL.
 */
class CheckoutService
{
    public function __construct(
        private readonly PriceCalculator $prices,
        private readonly CouponReservations $reservations,
        private readonly OrderStateMachine $states,
        private readonly PaystackGateway $paystack,
        private readonly PaymentConfirmationService $confirmations,
    ) {}

    /**
     * @return array{redirect_url:?string, free:bool, payment:Payment}
     *
     * @throws CheckoutException
     */
    public function start(Order $order, string $email, ?string $couponCode, bool $createAccount, Request $request): array
    {
        $payable = [OrderStatus::FormSubmitted, OrderStatus::PaymentPending, OrderStatus::PaymentFailed, OrderStatus::PaymentExpired];
        if (! in_array($order->status, $payable, true) || $order->payment_status === PaymentStatus::Paid) {
            throw new CheckoutException('This order has already been paid or can no longer be paid.');
        }

        $order->loadMissing('service');
        if (! $order->service || ! $order->service->is_active || $order->service->trashed()) {
            throw new CheckoutException('This service is no longer available.');
        }

        $email = strtolower(trim($email));

        // Re-use an open Paystack session if nothing about the price changed.
        $quote = $this->prices->quote($order->service, $couponCode, $email, $order);
        if ($couponCode && $quote->couponStatus === 'invalid') {
            throw new CheckoutException($quote->couponMessage ?: "This coupon code isn't valid.", 'coupon');
        }

        $reusable = $this->reusablePayment($order, $quote->total, $quote->currency, $email);
        if ($reusable && $quote->couponCode === $order->coupon_code) {
            return ['redirect_url' => $reusable->authorization_url, 'free' => false, 'payment' => $reusable];
        }

        $payment = DB::transaction(function () use ($order, $email, $quote, $createAccount, $request, $payable) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, $payable, true) || $locked->payment_status === PaymentStatus::Paid) {
                throw new CheckoutException('This order has already been paid or can no longer be paid.');
            }

            $order->forceFill([
                'email' => $email,
                'create_account' => $createAccount,
                'subtotal_amount' => $quote->baseAmount,
                'promotion_id' => $quote->promotion?->id,
                'promotion_discount' => $quote->promotionDiscount,
                'coupon_id' => $quote->coupon?->id,
                'coupon_code' => $quote->couponCode,
                'coupon_discount' => $quote->couponDiscount,
                'total_amount' => $quote->total,
                'currency' => $quote->currency,
                'pricing_snapshot' => $quote->toSnapshot(),
                'ip_address' => $request->ip(),
            ])->save();

            $holdMinutes = (int) Settings::get('orders.payment_expiry_hours', 24) * 60;
            if (! $this->reservations->reserve($order, $quote->couponDiscount, $holdMinutes)) {
                throw new CheckoutException('This coupon is no longer available.', 'coupon');
            }

            $free = $quote->total === 0;
            if ($free && ! Settings::get('payments.allow_free_orders', true)) {
                throw new CheckoutException('This order cannot be completed with the current discount.');
            }

            $payment = Payment::query()->create([
                'order_id' => $order->id,
                'purpose' => 'order',
                'provider' => $free ? 'internal' : 'paystack',
                'reference' => PaymentReference::generate(),
                'amount' => $quote->total,
                'currency' => $quote->currency,
                'status' => PaymentRecordStatus::Initialized,
                'customer_email' => $email,
                'ip_address' => $request->ip(),
                'expires_at' => now()->addMinutes($holdMinutes),
            ]);

            $order->forceFill([
                'payment_reference' => $payment->reference,
                'payment_status' => $free ? PaymentStatus::Unpaid : PaymentStatus::Pending,
            ])->save();

            if (! $free && $order->status !== OrderStatus::PaymentPending) {
                $this->states->transition($order, OrderStatus::PaymentPending, 'customer');
            }

            return $payment;
        });

        Analytics::record(AnalyticsEvent::CHECKOUT_START, $request, ['service_id' => $order->service_id, 'order_id' => $order->id, 'value' => $quote->total]);

        if ($quote->total === 0) {
            $this->confirmations->confirmWaived($payment);

            return ['redirect_url' => null, 'free' => true, 'payment' => $payment->refresh()];
        }

        try {
            $init = $this->paystack->initialize(
                email: $email,
                amount: $payment->amount,
                currency: $payment->currency,
                reference: $payment->reference,
                callbackUrl: route('checkout.callback'),
                metadata: [
                    'order_reference' => $order->reference,
                    'cancel_action' => route('checkout.payment', $order->reference),
                    'custom_fields' => [
                        ['display_name' => 'Order', 'variable_name' => 'order_reference', 'value' => $order->reference],
                        ['display_name' => 'Service', 'variable_name' => 'service', 'value' => $order->serviceName()],
                    ],
                ],
            );
        } catch (PaystackException $e) {
            Log::error('Paystack initialize failed', ['order' => $order->reference, 'error' => $e->getMessage()]);
            $payment->forceFill(['status' => PaymentRecordStatus::Failed, 'failure_reason' => 'initialize_failed'])->save();
            $this->reservations->release($order);
            $this->states->transitionIfAllowed($order, OrderStatus::PaymentFailed, 'system', 'Payment provider unavailable');

            throw new CheckoutException("We couldn't connect to our payment provider. Please try again in a moment.");
        }

        $payment->forceFill([
            'authorization_url' => $init['authorization_url'],
            'access_code' => $init['access_code'],
        ])->save();

        return ['redirect_url' => $init['authorization_url'], 'free' => false, 'payment' => $payment];
    }

    private function reusablePayment(Order $order, int $total, string $currency, string $email): ?Payment
    {
        $payment = $order->payments()
            ->where('purpose', 'order')
            ->where('reference', $order->payment_reference)
            ->where('status', PaymentRecordStatus::Initialized->value)
            ->whereNotNull('authorization_url')
            ->where('created_at', '>', now()->subMinutes(30))
            ->first();

        return $payment && $payment->amount === $total && $payment->currency === $currency && $payment->customer_email === $email
            ? $payment
            : null;
    }
}
