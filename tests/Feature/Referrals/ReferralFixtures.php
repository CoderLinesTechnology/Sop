<?php

namespace Tests\Feature\Referrals;

use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Referrer;
use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Builders for the referral programme tests. */
final class ReferralFixtures
{
    public static function referrer(array $attributes = []): Referrer
    {
        return Referrer::query()->create(array_merge([
            'name' => 'Kofi Mensah',
            'email' => 'kofi@example.com',
            'phone' => '+233 20 000 0000',
            'code' => 'KOFI'.strtoupper(Str::random(4)),
            'commission_rate_bp' => 1250,
            'is_active' => true,
        ], $attributes));
    }

    /** A random checkout cookie value, as CheckoutSession issues them. */
    public static function browserToken(): string
    {
        return Str::random(43);
    }

    /** A draft order bound to the browser holding $token. */
    public static function draft(string $token, array $attributes = []): Order
    {
        $service = Service::factory()->withStandardFields()->create(['price' => 8900, 'currency' => 'USD']);

        return Order::factory()->create(array_merge([
            'service_id' => $service->id,
            'status' => OrderStatus::FormSubmitted->value,
            'checkout_token_hash' => hash('sha256', $token),
            'total_amount' => 8900,
        ], $attributes));
    }

    /** An order waiting for Paystack, attributed to $referrer, with an initialized payment of $amount. */
    public static function pendingReferredPayment(Referrer $referrer, int $amount = 8010, string $currency = 'USD', array $orderAttributes = []): Payment
    {
        $order = Order::factory()->create(array_merge([
            'status' => OrderStatus::PaymentPending->value,
            'payment_status' => PaymentStatus::Pending->value,
            'total_amount' => $amount,
            'currency' => $currency,
        ], $orderAttributes));
        $order->forceFill([
            'referrer_id' => $referrer->id,
            'referral_code' => $referrer->code,
            'referral_source' => 'link',
            'referred_at' => now(),
        ])->save();

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'purpose' => 'order',
            'provider' => 'paystack',
            'reference' => 'STX-'.strtoupper(bin2hex(random_bytes(8))),
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentRecordStatus::Initialized,
            'customer_email' => $order->email,
            'authorization_url' => 'https://checkout.paystack.com/test',
            'expires_at' => now()->addDay(),
        ]);
        $order->forceFill(['payment_reference' => $payment->reference])->save();

        return $payment;
    }

    /** A paid order attributed to $referrer (verified payment, no ledger entry yet). */
    public static function paidReferredOrder(Referrer $referrer, int $amount = 10000, array $attributes = []): Order
    {
        $order = Order::factory()->paid()->create(array_merge(['total_amount' => $amount, 'currency' => 'USD'], $attributes));
        $order->forceFill([
            'referrer_id' => $referrer->id,
            'referral_code' => $referrer->code,
            'referral_source' => 'code',
            'referred_at' => now(),
        ])->save();

        return $order;
    }

    /** Make Paystack's verify endpoint report a successful transaction for each given payment. */
    public static function paystackVerifies(Payment ...$payments): void
    {
        $byReference = [];
        foreach ($payments as $payment) {
            $byReference[$payment->reference] = $payment;
        }

        Http::fake([
            'api.paystack.co/transaction/verify/*' => function ($request) use ($byReference) {
                $reference = urldecode(basename(parse_url($request->url(), PHP_URL_PATH)));
                $payment = $byReference[$reference];

                return Http::response(['status' => true, 'message' => 'Verification successful', 'data' => [
                    'id' => random_int(1000, 999999),
                    'status' => 'success',
                    'reference' => $payment->reference,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'paid_at' => now()->toIso8601String(),
                    'channel' => 'card',
                    'gateway_response' => 'Successful',
                    'customer' => ['email' => $payment->customer_email],
                ]]);
            },
            'api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => random_int(1000, 999999), 'status' => 'processed']]),
        ]);
    }
}
