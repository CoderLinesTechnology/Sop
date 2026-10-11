<?php

use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Payments\ConfirmationOutcome;
use App\Domain\Payments\PaymentConfirmationService;
use App\Domain\Payments\RefundService;
use App\Domain\Referrals\ReferralCommissions;
use App\Domain\Referrals\Tasks\ReconcileReferralCommissions;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ReferralCommission;
use App\Models\Refund;
use App\Support\Settings;
use Tests\Feature\Referrals\ReferralFixtures;

beforeEach(function () {
    config()->set('statementra.runtime.tasks', []);
    Settings::flush();
    $this->mock(PipelineDispatcher::class)->shouldReceive('startForOrder', 'cancel', 'startRevision');
});

/** A processed refund of $amount on $payment, through the real RefundService (Paystack faked). */
function referralRefund(Payment $payment, int $amount): Refund
{
    $admin = AdminUser::factory()->create();
    $refunds = app(RefundService::class);
    $refund = $refunds->request($payment->order->refresh(), $amount, 'Customer request', $admin);

    return $refunds->approve($refund, $admin);
}

it('records one commission per payment even when the payment is confirmed twice', function () {
    $referrer = ReferralFixtures::referrer(['commission_rate_bp' => 1250]);
    $payment = ReferralFixtures::pendingReferredPayment($referrer, 8010);
    ReferralFixtures::paystackVerifies($payment);

    $confirmations = app(PaymentConfirmationService::class);
    expect($confirmations->confirmByReference($payment->reference, 'callback'))->toBe(ConfirmationOutcome::Confirmed);

    // The webhook arrives later with the same data; and a retry replays the ledger write itself.
    $confirmations->applyVerification($payment->refresh(), ['status' => 'success', 'reference' => $payment->reference, 'amount' => 8010, 'currency' => 'USD'], 'webhook');
    app(ReferralCommissions::class)->recordForPayment($payment);

    $entry = ReferralCommission::query()->sole();
    expect($entry->kind)->toBe(ReferralCommission::KIND_PAYMENT)
        ->and($entry->referrer_id)->toBe($referrer->id)
        ->and($entry->order_id)->toBe($payment->order_id)
        ->and($entry->payment_id)->toBe($payment->id)
        ->and($entry->base_amount)->toBe(8010)
        ->and($entry->currency)->toBe('USD')
        ->and($entry->rate_bp)->toBe(1250)
        ->and($entry->amount)->toBe(1001) // floor(8010 × 12.5%) = floor(1001.25)
        ->and($entry->paid_at)->toBeNull();
});

it('snapshots the rate at payment time and earns nothing on unattributed or free orders', function () {
    $referrer = ReferralFixtures::referrer(['commission_rate_bp' => 1000]);
    $payment = ReferralFixtures::pendingReferredPayment($referrer, 5000);
    ReferralFixtures::paystackVerifies($payment);
    app(PaymentConfirmationService::class)->confirmByReference($payment->reference, 'callback');

    $referrer->forceFill(['commission_rate_bp' => 3000])->save();
    expect(ReferralCommission::query()->sole()->rate_bp)->toBe(1000);

    $unattributed = Order::factory()->paid()->create();
    expect(app(ReferralCommissions::class)->recordForPayment($unattributed->payments()->sole()))->toBeNull()
        ->and(ReferralCommission::query()->count())->toBe(1);
});

it('earns commission on a paid revision fee of a referred order', function () {
    $referrer = ReferralFixtures::referrer(['commission_rate_bp' => 2000]);
    $order = ReferralFixtures::paidReferredOrder($referrer, 10000);
    $revisionPayment = Payment::query()->create([
        'order_id' => $order->id,
        'purpose' => 'revision',
        'provider' => 'paystack',
        'reference' => 'STX-REV-'.strtoupper(bin2hex(random_bytes(5))),
        'amount' => 1599,
        'currency' => 'USD',
        'status' => PaymentRecordStatus::Initialized,
        'customer_email' => $order->email,
    ]);
    ReferralFixtures::paystackVerifies($revisionPayment);

    expect(app(PaymentConfirmationService::class)->confirmByReference($revisionPayment->reference, 'callback'))->toBe(ConfirmationOutcome::Confirmed);

    $entry = ReferralCommission::query()->where('payment_id', $revisionPayment->id)->sole();
    expect($entry->kind)->toBe(ReferralCommission::KIND_REVISION_PAYMENT)
        ->and($entry->amount)->toBe(319); // floor(1599 × 20%) = floor(319.8)
});

it('reverses commission in proportion to refunds, never more than was earned', function () {
    $referrer = ReferralFixtures::referrer(['commission_rate_bp' => 1250]);
    $payment = ReferralFixtures::pendingReferredPayment($referrer, 10001);
    ReferralFixtures::paystackVerifies($payment);
    app(PaymentConfirmationService::class)->confirmByReference($payment->reference, 'callback');
    expect(ReferralCommission::query()->sole()->amount)->toBe(1250); // floor(1250.125)

    $first = referralRefund($payment, 3333);
    expect($first->status)->toBe(RefundStatus::Processed);
    $reversal = ReferralCommission::query()->where('refund_id', $first->id)->sole();
    // Kept: floor((10001 − 3333) × 12.5%) = 833 → reversal 1250 − 833 = 417
    expect($reversal->kind)->toBe(ReferralCommission::KIND_REFUND_REVERSAL)
        ->and($reversal->base_amount)->toBe(-3333)
        ->and($reversal->amount)->toBe(-417)
        ->and($reversal->payment_id)->toBeNull()
        ->and($reversal->order_id)->toBe($payment->order_id);

    // Finalising the same refund again (e.g. a late webhook) adds nothing.
    app(ReferralCommissions::class)->reverseForRefund($first);
    expect(ReferralCommission::query()->where('kind', ReferralCommission::KIND_REFUND_REVERSAL)->count())->toBe(1);

    referralRefund($payment, 10001 - 3333);

    expect((int) ReferralCommission::query()->sum('amount'))->toBe(0)
        ->and((int) ReferralCommission::query()->sum('base_amount'))->toBe(0)
        ->and($payment->refresh()->status)->toBe(PaymentRecordStatus::Refunded)
        ->and($payment->order->refresh()->payment_status)->toBe(PaymentStatus::Refunded);
});

it('never lets a ledger failure break or roll back a payment confirmation', function () {
    $referrer = ReferralFixtures::referrer();
    $payment = ReferralFixtures::pendingReferredPayment($referrer, 8000);
    ReferralFixtures::paystackVerifies($payment);
    ReferralCommission::creating(fn () => throw new RuntimeException('ledger unavailable'));

    expect(app(PaymentConfirmationService::class)->confirmByReference($payment->reference, 'callback'))->toBe(ConfirmationOutcome::Confirmed)
        ->and($payment->refresh()->status)->toBe(PaymentRecordStatus::Success)
        ->and($payment->order->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and(ReferralCommission::query()->count())->toBe(0);
});

it('fills ledger gaps from the heartbeat, skipping self-referrals', function () {
    $referrer = ReferralFixtures::referrer(['commission_rate_bp' => 1000, 'email' => 'partner@example.com']);
    $missed = ReferralFixtures::paidReferredOrder($referrer, 20000);
    ReferralFixtures::paidReferredOrder($referrer, 20000, ['email' => 'partner@example.com']);

    app()->call([app(ReconcileReferralCommissions::class), '__invoke']);
    app()->call([app(ReconcileReferralCommissions::class), '__invoke']);

    $entry = ReferralCommission::query()->sole();
    expect($entry->order_id)->toBe($missed->id)
        ->and($entry->amount)->toBe(2000);
});

it('makes commission ledger entries undeletable', function () {
    $referrer = ReferralFixtures::referrer();
    $order = ReferralFixtures::paidReferredOrder($referrer);
    $entry = app(ReferralCommissions::class)->recordForPayment($order->payments()->sole());

    expect(fn () => $entry->delete())->toThrow(LogicException::class);
    expect(ReferralCommission::query()->count())->toBe(1);
});
