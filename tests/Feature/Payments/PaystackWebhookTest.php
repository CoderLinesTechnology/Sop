<?php

use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Payments\Paystack\WebhookSignature;
use App\Domain\Payments\Tasks\ProcessPendingPaymentEvents;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/PaymentConfirmationTest.php';

/** POST a raw webhook body the way Paystack does. */
function postWebhook(string $body, ?string $signature)
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($signature !== null) {
        $server['HTTP_X_PAYSTACK_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/webhooks/paystack', [], [], [], $server, $body);
}

function chargeSuccessBody(Payment $payment): string
{
    return (string) json_encode(['event' => 'charge.success', 'data' => [
        'id' => 4099260516,
        'status' => 'success',
        'reference' => $payment->reference,
        'amount' => $payment->amount,
        'currency' => $payment->currency,
    ]]);
}

beforeEach(function () {
    $this->pipeline = $this->mock(PipelineDispatcher::class);
});

it('rejects a webhook with a bad signature and keeps only its fingerprint', function () {
    $payment = pendingPayment();
    Http::fake();
    $this->pipeline->shouldNotReceive('startForOrder');

    postWebhook(chargeSuccessBody($payment), str_repeat('a', 128))->assertStatus(401);
    postWebhook(chargeSuccessBody($payment).' ', null)->assertStatus(401);

    $event = PaymentEvent::query()->first();
    expect($event->signature_valid)->toBeFalse()
        ->and($event->payload)->toBeNull()
        ->and($event->processing_status)->toBe('rejected')
        ->and($payment->order->refresh()->payment_status)->not->toBe(PaymentStatus::Paid)
        ->and(SecurityEvent::query()->where('type', 'invalid_webhook_signature')->count())->toBe(2);
    Http::assertNothingSent();
});

it('acknowledges a signed webhook and confirms the order after re-verifying with Paystack', function () {
    $payment = pendingPayment();
    paystackReports($payment);
    $this->pipeline->shouldReceive('startForOrder')->once();

    $body = chargeSuccessBody($payment);
    postWebhook($body, WebhookSignature::compute($body, WebhookSignature::secret()))
        ->assertOk()->assertJson(['status' => 'accepted']);

    expect($payment->order->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and(PaymentEvent::query()->sole()->processing_status)->toBe('processed');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/transaction/verify/'.$payment->reference));
});

it('ignores a replayed webhook', function () {
    $payment = pendingPayment();
    paystackReports($payment);
    $this->pipeline->shouldReceive('startForOrder')->once();

    $body = chargeSuccessBody($payment);
    $signature = WebhookSignature::compute($body, WebhookSignature::secret());

    postWebhook($body, $signature)->assertOk()->assertJson(['status' => 'accepted']);
    postWebhook($body, $signature)->assertOk()->assertJson(['status' => 'duplicate']);

    expect(PaymentEvent::query()->count())->toBe(1);
});

it('treats the webhook payload only as a hint: Paystack must confirm the charge', function () {
    $payment = pendingPayment();
    paystackReports($payment, ['status' => 'abandoned']);
    $this->pipeline->shouldNotReceive('startForOrder');

    $body = chargeSuccessBody($payment);
    postWebhook($body, WebhookSignature::compute($body, WebhookSignature::secret()))->assertOk();

    expect($payment->order->refresh()->payment_status)->not->toBe(PaymentStatus::Paid);
});

it('retries a webhook from the heartbeat when Paystack was unreachable', function () {
    $payment = pendingPayment();
    $paystackUp = false;
    Http::fake(function () use (&$paystackUp, $payment) {
        return $paystackUp
            ? Http::response(['status' => true, 'message' => 'Verification successful', 'data' => [
                'status' => 'success', 'reference' => $payment->reference, 'amount' => $payment->amount, 'currency' => $payment->currency,
            ]])
            : Http::response(['status' => false, 'message' => 'Service unavailable'], 503);
    });
    $this->pipeline->shouldReceive('startForOrder')->once();

    $body = chargeSuccessBody($payment);
    postWebhook($body, WebhookSignature::compute($body, WebhookSignature::secret()))->assertOk();

    $event = PaymentEvent::query()->sole();
    expect($event->processing_status)->toBe('pending')
        ->and($event->attempts)->toBe(1)
        ->and($payment->order->refresh()->payment_status)->not->toBe(PaymentStatus::Paid);

    // Paystack recovers; the next heartbeat after the backoff processes the event.
    $paystackUp = true;
    $this->travel(2)->minutes();
    app()->call([app(ProcessPendingPaymentEvents::class), '__invoke']);

    expect($event->refresh()->processing_status)->toBe('processed')
        ->and($event->attempts)->toBe(2)
        ->and($payment->order->refresh()->payment_status)->toBe(PaymentStatus::Paid);
});
