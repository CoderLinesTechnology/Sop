<?php

use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Payments\ConfirmationOutcome;
use App\Domain\Payments\PaymentConfirmationService;
use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Models\EmailMessage;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Http;

/** An order waiting for payment, with an initialized Paystack payment session. */
function pendingPayment(int $amount = 8010, string $currency = 'USD'): Payment
{
    $order = Order::factory()->create([
        'status' => OrderStatus::PaymentPending->value,
        'payment_status' => PaymentStatus::Pending->value,
        'total_amount' => $amount,
        'currency' => $currency,
    ]);

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

/** Make Paystack's verify endpoint report the given transaction. */
function paystackReports(Payment $payment, array $overrides = []): void
{
    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => array_merge([
                'id' => 4099260516,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'paid_at' => now()->toIso8601String(),
                'channel' => 'card',
                'gateway_response' => 'Successful',
                'customer' => ['email' => $payment->customer_email],
                'fees' => 150,
            ], $overrides),
        ]),
    ]);
}

beforeEach(function () {
    $this->pipeline = $this->mock(PipelineDispatcher::class);
});

it('confirms a verified payment exactly once and starts fulfilment once', function () {
    $payment = pendingPayment();
    paystackReports($payment);
    $this->pipeline->shouldReceive('startForOrder')->once();

    $service = app(PaymentConfirmationService::class);
    expect($service->confirmByReference($payment->reference, 'callback'))->toBe(ConfirmationOutcome::Confirmed)
        ->and($service->confirmByReference($payment->reference, 'webhook'))->toBe(ConfirmationOutcome::AlreadyConfirmed);

    $order = $payment->order->refresh();
    expect($order->status)->toBe(OrderStatus::PaymentConfirmed)
        ->and($order->payment_status)->toBe(PaymentStatus::Paid)
        ->and($payment->refresh()->status)->toBe(PaymentRecordStatus::Success)
        ->and(EmailMessage::query()->where('order_id', $order->id)->where('template_key', 'payment_received')->count())->toBe(1);
});

it('refuses to fulfil when Paystack reports a different amount', function () {
    $payment = pendingPayment(8010);
    paystackReports($payment, ['amount' => 100]);
    $this->pipeline->shouldNotReceive('startForOrder');

    $outcome = app(PaymentConfirmationService::class)->confirmByReference($payment->reference, 'webhook');

    expect($outcome)->toBe(ConfirmationOutcome::Mismatch)
        ->and($payment->refresh()->status)->toBe(PaymentRecordStatus::Mismatch)
        ->and($payment->order->refresh()->payment_status)->not->toBe(PaymentStatus::Paid)
        ->and(SecurityEvent::query()->where('type', 'payment_mismatch')->exists())->toBeTrue();
});

it('refuses to fulfil when Paystack reports a different currency', function () {
    $payment = pendingPayment(8010, 'USD');
    paystackReports($payment, ['currency' => 'NGN']);
    $this->pipeline->shouldNotReceive('startForOrder');

    expect(app(PaymentConfirmationService::class)->confirmByReference($payment->reference, 'callback'))
        ->toBe(ConfirmationOutcome::Mismatch);
});

it('refuses to fulfil when the verified reference does not match', function () {
    $payment = pendingPayment();
    paystackReports($payment, ['reference' => 'STX-SOMEONE-ELSES-PAYMENT']);
    $this->pipeline->shouldNotReceive('startForOrder');

    expect(app(PaymentConfirmationService::class)->confirmByReference($payment->reference, 'callback'))
        ->toBe(ConfirmationOutcome::Mismatch);
});

it('records a failed charge without fulfilling', function () {
    $payment = pendingPayment();
    paystackReports($payment, ['status' => 'failed', 'gateway_response' => 'Declined']);
    $this->pipeline->shouldNotReceive('startForOrder');

    expect(app(PaymentConfirmationService::class)->confirmByReference($payment->reference, 'callback'))
        ->toBe(ConfirmationOutcome::Failed)
        ->and($payment->order->refresh()->payment_status)->not->toBe(PaymentStatus::Paid);
});

it('treats an unknown reference as suspicious', function () {
    Http::fake();
    $this->pipeline->shouldNotReceive('startForOrder');

    expect(app(PaymentConfirmationService::class)->confirmByReference('STX-DOES-NOT-EXIST', 'callback'))
        ->toBe(ConfirmationOutcome::NotFound)
        ->and(SecurityEvent::query()->where('type', 'unknown_payment_reference')->exists())->toBeTrue();

    Http::assertNothingSent();
});

it('never confirms a cancelled order, even if the charge succeeded', function () {
    $payment = pendingPayment();
    $payment->order->forceFill(['status' => OrderStatus::Cancelled])->save();
    paystackReports($payment);
    $this->pipeline->shouldNotReceive('startForOrder');

    expect(app(PaymentConfirmationService::class)->confirmByReference($payment->reference, 'webhook'))
        ->toBe(ConfirmationOutcome::Mismatch);
});

it('keeps sending the confirmation email when starting fulfilment fails', function () {
    $payment = pendingPayment();
    paystackReports($payment);
    $this->pipeline->shouldReceive('startForOrder')->once()->andThrow(new RuntimeException('AI provider down'));

    expect(app(PaymentConfirmationService::class)->confirmByReference($payment->reference, 'callback'))
        ->toBe(ConfirmationOutcome::Confirmed)
        ->and(EmailMessage::query()->where('order_id', $payment->order_id)->where('template_key', 'payment_received')->exists())->toBeTrue();
});
