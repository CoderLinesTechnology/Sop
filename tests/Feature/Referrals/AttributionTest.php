<?php

use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Orders\CheckoutSession;
use App\Domain\Payments\PaymentConfirmationService;
use App\Domain\Referrals\ReferralAttribution;
use App\Domain\Referrals\ReferralProgramme;
use App\Enums\OrderStatus;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\ReferralCommission;
use App\Models\SecurityEvent;
use App\Models\Service;
use App\Support\Settings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Referrals\ReferralFixtures;

beforeEach(function () {
    config()->set('statementra.runtime.tasks', []);
    Settings::flush();
});

function referralFakePaystackInitialize(): void
{
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
        'status' => true, 'message' => 'Authorization URL created',
        'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'access_code' => 'abc', 'reference' => 'x'],
    ])]);
}

function referralPayPayload(array $extra = []): array
{
    return array_merge(['email' => 'ama@example.com', 'confirm_accuracy' => '1'], $extra);
}

it('attributes the draft to the remembered link when the order form is submitted, without changing the price', function () {
    $referrer = ReferralFixtures::referrer(['code' => 'LINK1']);
    $service = Service::factory()->withStandardFields()->create(['price' => 8900, 'currency' => 'USD']);

    $this->withCookie(ReferralProgramme::COOKIE, 'LINK1')
        ->post("/start/{$service->slug}", ['answers' => [
            'full_name' => 'Ama Owusu',
            'email' => 'ama@example.com',
            'institution' => 'University of Ghana',
            'programme' => 'MSc Data Science',
            'country' => 'GH',
            'background' => str_repeat('I studied statistics and worked as an analyst for two years. ', 4),
        ]])
        ->assertRedirect();

    $order = Order::query()->sole();
    expect($order->referrer_id)->toBe($referrer->id)
        ->and($order->referral_code)->toBe('LINK1')
        ->and($order->referral_source)->toBe(ReferralAttribution::SOURCE_LINK)
        ->and($order->referred_at)->not->toBeNull()
        ->and($order->total_amount)->toBe(8900);
});

it('shows the referral box prefilled and applied when the link cookie is present, and hides it when the programme is off', function () {
    ReferralFixtures::referrer(['code' => 'PREFILL']);
    $token = ReferralFixtures::browserToken();
    $order = ReferralFixtures::draft($token);

    $this->withCookie(CheckoutSession::COOKIE, $token)->withCookie(ReferralProgramme::COOKIE, 'PREFILL')
        ->get("/checkout/{$order->reference}/payment")
        ->assertOk()
        ->assertSee('Referred by someone?')
        ->assertSee('value="PREFILL"', false)
        ->assertSee(' Applied</p>', false);

    $this->withCookie(CheckoutSession::COOKIE, $token)->withCookie(ReferralProgramme::COOKIE, 'NOSUCH')
        ->get("/checkout/{$order->reference}/payment")
        ->assertOk()
        ->assertDontSee(' Applied</p>', false);

    Settings::set('referrals.enabled', false);
    $this->withCookie(CheckoutSession::COOKIE, $token)->get("/checkout/{$order->reference}/payment")
        ->assertOk()->assertDontSee('Referred by someone?');
});

it('attributes at pay time from the link when the order has no referrer yet', function () {
    $referrer = ReferralFixtures::referrer(['code' => 'LATE']);
    $token = ReferralFixtures::browserToken();
    $order = ReferralFixtures::draft($token);
    referralFakePaystackInitialize();

    $this->withCookie(CheckoutSession::COOKIE, $token)->withCookie(ReferralProgramme::COOKIE, 'LATE')
        ->post("/checkout/{$order->reference}/pay", referralPayPayload())
        ->assertRedirect('https://checkout.paystack.com/abc');

    expect($order->refresh()->referrer_id)->toBe($referrer->id)
        ->and($order->referral_source)->toBe('link');
});

it('lets a typed code override the link, and the price stays the same', function () {
    $link = ReferralFixtures::referrer(['code' => 'LINKED']);
    $typed = ReferralFixtures::referrer(['code' => 'TYPED', 'email' => 'typed@example.com']);
    $token = ReferralFixtures::browserToken();
    $order = ReferralFixtures::draft($token);
    app(ReferralAttribution::class)->fromLink($order, tap(Request::create('/'), fn ($r) => $r->cookies->set(ReferralProgramme::COOKIE, 'LINKED')));
    expect($order->refresh()->referrer_id)->toBe($link->id);
    referralFakePaystackInitialize();

    $this->withCookie(CheckoutSession::COOKIE, $token)->withCookie(ReferralProgramme::COOKIE, 'LINKED')
        ->post("/checkout/{$order->reference}/pay", referralPayPayload(['referral_code' => ' typed ']))
        ->assertRedirect('https://checkout.paystack.com/abc');

    $order->refresh();
    expect($order->referrer_id)->toBe($typed->id)
        ->and($order->referral_code)->toBe('TYPED')
        ->and($order->referral_source)->toBe(ReferralAttribution::SOURCE_CODE)
        ->and($order->total_amount)->toBe(8900);
    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/transaction/initialize') && $request['amount'] === 8900);
});

it('shows a friendly error for an unknown typed code and does not start the payment', function () {
    $token = ReferralFixtures::browserToken();
    $order = ReferralFixtures::draft($token);
    Coupon::query()->create(['code' => 'SAVE10', 'percent_off' => 10, 'is_active' => true]);
    Http::fake();

    foreach (['NOPE', 'SAVE10'] as $code) {
        $this->withCookie(CheckoutSession::COOKIE, $token)
            ->from("/checkout/{$order->reference}/payment")
            ->post("/checkout/{$order->reference}/pay", referralPayPayload(['referral_code' => $code]))
            ->assertRedirect("/checkout/{$order->reference}/payment")
            ->assertSessionHasErrors(['referral_code' => 'We couldn’t find that referral code. Check it with the person who referred you, or leave the box empty.']);
    }

    Http::assertNothingSent();
    expect($order->refresh()->referrer_id)->toBeNull()
        ->and($order->status)->toBe(OrderStatus::FormSubmitted);
});

it('rate-limits referral code guessing like coupon guessing and flags it', function () {
    $token = ReferralFixtures::browserToken();
    $order = ReferralFixtures::draft($token);
    ReferralFixtures::referrer(['code' => 'REALCODE']);
    Settings::set('security.rate_limit_orders_per_hour', 100); // the route's own checkout throttle is not what is under test
    Http::fake();

    foreach (range(1, 15) as $i) {
        $this->withCookie(CheckoutSession::COOKIE, $token)
            ->post("/checkout/{$order->reference}/pay", referralPayPayload(['referral_code' => 'GUESS'.$i]))
            ->assertSessionHasErrors('referral_code');
    }

    // Even the right code is refused once the limit is reached.
    $this->withCookie(CheckoutSession::COOKIE, $token)
        ->post("/checkout/{$order->reference}/pay", referralPayPayload(['referral_code' => 'REALCODE']))
        ->assertSessionHasErrors(['referral_code' => 'Too many referral code attempts. Please try again later, or leave the box empty.']);

    expect(SecurityEvent::query()->where('type', 'referral_code_bruteforce')->exists())->toBeTrue()
        ->and($order->refresh()->referrer_id)->toBeNull();
    Http::assertNothingSent();
});

it('never changes the attribution once the order is paid', function () {
    $original = ReferralFixtures::referrer(['code' => 'ORIGINAL']);
    $other = ReferralFixtures::referrer(['code' => 'OTHER', 'email' => 'other@example.com']);
    $order = ReferralFixtures::paidReferredOrder($original);

    $request = Request::create('/');
    $request->cookies->set(ReferralProgramme::COOKIE, 'OTHER');

    expect(app(ReferralAttribution::class)->atCheckout($order, 'OTHER', $request))->toBeNull();
    app(ReferralAttribution::class)->fromLink($order->forceFill(['referrer_id' => null]), $request);

    $order = $order->fresh();
    expect($order->referrer_id)->toBe($original->id)
        ->and($order->referral_code)->toBe('ORIGINAL')
        ->and(ReferralAttribution::isLocked($order))->toBeTrue();

    // A paid order is not payable again, so the checkout never reaches attribution either.
    $token = ReferralFixtures::browserToken();
    $order->forceFill(['checkout_token_hash' => hash('sha256', $token)])->save();
    $this->withCookie(CheckoutSession::COOKIE, $token)
        ->post("/checkout/{$order->reference}/pay", referralPayPayload(['referral_code' => 'OTHER']))
        ->assertRedirect(route('orders.show', $order->public_id));
    expect($order->fresh()->referrer_id)->toBe($original->id);
});

it('records a self-referral but earns no commission on it', function () {
    $referrer = ReferralFixtures::referrer(['code' => 'MYSELF', 'email' => 'Kofi@Example.com']);
    $payment = ReferralFixtures::pendingReferredPayment($referrer, 10000, 'USD', ['email' => 'kofi@example.com']);
    ReferralFixtures::paystackVerifies($payment);
    $this->mock(PipelineDispatcher::class)->shouldReceive('startForOrder');

    app(PaymentConfirmationService::class)->confirmByReference($payment->reference, 'callback');

    expect($payment->order->refresh()->referrer_id)->toBe($referrer->id)
        ->and($payment->order->isPaid())->toBeTrue()
        ->and(ReferralCommission::query()->count())->toBe(0);
});
