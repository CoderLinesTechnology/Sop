<?php

use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Payments\PaymentConfirmationService;
use App\Domain\Payments\RefundService;
use App\Domain\Referrals\ReferrerService;
use App\Models\AdminUser;
use App\Models\EmailMessage;
use App\Models\Order;
use App\Models\ReferralCommission;
use App\Models\ReferralVisit;
use App\Models\SecurityEvent;
use App\Models\Service;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Referrals\ReferralFixtures;

beforeEach(function () {
    config()->set('statementra.runtime.tasks', []);
    Settings::flush();
    $this->mock(PipelineDispatcher::class)->shouldReceive('startForOrder', 'cancel');
});

/** A referrer with visits, a started order, two paid orders (one partly refunded) and a payout. */
function referrerWithActivity(): array
{
    $referrer = ReferralFixtures::referrer(['code' => 'DASH', 'commission_rate_bp' => 1000]);
    ReferralVisit::query()->insert([
        ['referrer_id' => $referrer->id, 'date' => now()->subDays(3)->toDateString(), 'visits' => 7],
        ['referrer_id' => $referrer->id, 'date' => now()->subDays(90)->toDateString(), 'visits' => 5],
    ]);

    $paid = ReferralFixtures::pendingReferredPayment($referrer, 20000, 'USD', ['customer_name' => 'Abena Secret', 'email' => 'abena.secret@example.com']);
    $refunded = ReferralFixtures::pendingReferredPayment($referrer, 10000, 'USD', ['customer_name' => 'Yaw Hidden', 'email' => 'yaw.hidden@example.com']);
    ReferralFixtures::paystackVerifies($paid, $refunded);
    app(PaymentConfirmationService::class)->confirmByReference($paid->reference, 'callback');
    app(PaymentConfirmationService::class)->confirmByReference($refunded->reference, 'callback');

    $admin = AdminUser::factory()->create();
    $refund = app(RefundService::class)->request($refunded->order->refresh(), 5000, 'Partial refund', $admin);
    app(RefundService::class)->approve($refund, $admin);

    // A draft that never reached payment does not count as started; one waiting for Paystack does.
    $draft = Order::factory()->create(['customer_name' => 'Draft Person']);
    $draft->forceFill(['referrer_id' => $referrer->id, 'referral_code' => 'DASH', 'referral_source' => 'link'])->save();
    ReferralFixtures::pendingReferredPayment($referrer, 9000);

    $first = ReferralCommission::query()->where('payment_id', $paid->id)->sole();
    // An earlier payout (stamped as ReferralPayouts::markPaid does).
    $first->forceFill(['paid_at' => now(), 'payout_reference' => 'MOMO-123', 'paid_by_admin_id' => $admin->id])->save();

    return [$referrer->refresh(), [$paid->order->refresh(), $refunded->order->refresh(), $draft]];
}

it('shows the referrer counts and money only, with private headers', function () {
    [$referrer, $orders] = referrerWithActivity();

    $response = $this->get('/partner/'.$referrer->dashboard_token)
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('Hi Kofi Mensah')
        ->assertSee(route('home', ['ref' => 'DASH']), false)
        ->assertSee('10%')
        ->assertSee('Partially refunded');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $html = $response->getContent();
    // Visits 7 (30 days) / 12 (all time); 3 orders reached payment (a draft does not count); 2 paid.
    expect($html)
        ->toMatch('~Visits, last 30 days</dt>\s*<dd[^>]*>7</dd>~')
        ->toMatch('~Visits, all time</dt>\s*<dd[^>]*>12</dd>~')
        ->toMatch('~Orders started</dt>\s*<dd[^>]*>3</dd>~')
        ->toMatch('~Paid orders</dt>\s*<dd[^>]*>2</dd>~')
        // Revenue 20000 + 10000 − 5000 = $250; commission 2000 + 1000 − 500 = $25; paid $20;
        // the refunded order's $10 is still in the hold, its −$5 reversal counts at once.
        ->toContain('Referred revenue $250 · commission earned $25')
        ->toMatch('~Pending</dt>\s*<dd[^>]*>\$10</dd>~')
        ->toMatch('~Available</dt>\s*<dd[^>]*>-\$5</dd>~')
        ->toMatch('~Requested</dt>\s*<dd[^>]*>\$0</dd>~')
        ->toMatch('~Paid</dt>\s*<dd[^>]*>\$20</dd>~');

    // Nothing that identifies a customer or an order, and no internal ids.
    foreach ($orders as $order) {
        expect($html)->not->toContain($order->reference)
            ->not->toContain($order->public_id)
            ->not->toContain((string) $order->email);
        if ($order->customer_name) {
            expect($html)->not->toContain($order->customer_name);
        }
    }
    expect($html)->not->toContain('MOMO-123')->not->toContain($referrer->public_id);
});

it('computes the dashboard with a fixed number of queries whatever the number of orders', function () {
    [$referrer] = referrerWithActivity();
    $service = Service::factory()->create();
    foreach (range(1, 25) as $i) {
        ReferralFixtures::paidReferredOrder($referrer, 1000, ['service_id' => $service->id]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/partner/'.$referrer->dashboard_token)->assertOk()->assertSee('Page 1 of 2')->assertSee('Older ›');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $onOrders = $queries->filter(fn (string $sql) => str_contains($sql, '"orders"') || str_contains($sql, '`orders`'));
    expect($onOrders->count())->toBeLessThanOrEqual(3)
        ->and($queries->count())->toBeLessThan(30);

    // 27 paid orders: 20 on the first page, 7 on the second.
    $second = $this->get('/partner/'.$referrer->dashboard_token.'?page=2')->assertOk()->assertSee('Page 2 of 2')->getContent();
    expect(substr_count($second, '<tr>'))->toBe(1 + 7); // header row + 7 orders
});

it('answers 404 for unknown, replaced and disabled links, deactivated referrers and when the programme is off', function () {
    $referrer = ReferralFixtures::referrer();
    $oldToken = $referrer->dashboard_token;
    $admin = AdminUser::factory()->create();
    $service = app(ReferrerService::class);

    $this->get('/partner/'.$oldToken)->assertOk();
    $this->get('/partner/'.str_repeat('A', 48))->assertNotFound();

    $service->replaceDashboardLink($referrer, $admin);
    $this->get('/partner/'.$oldToken)->assertNotFound();
    $this->get('/partner/'.$referrer->refresh()->dashboard_token)->assertOk();

    $service->setActive($referrer, false, $admin);
    $this->get('/partner/'.$referrer->dashboard_token)->assertNotFound();
    $service->setActive($referrer, true, $admin);
    $this->get('/partner/'.$referrer->dashboard_token)->assertOk();

    Settings::set('referrals.enabled', false);
    $this->get('/partner/'.$referrer->dashboard_token)->assertNotFound();
    Settings::set('referrals.enabled', true);

    $token = $referrer->dashboard_token;
    $service->disableDashboardLink($referrer, $admin);
    $this->get('/partner/'.$token)->assertNotFound();
});

it('flags repeated guesses of dashboard links', function () {
    foreach (range(1, 10) as $i) {
        $this->get('/partner/'.str_repeat((string) $i % 10, 48))->assertNotFound();
    }

    expect(SecurityEvent::query()->where('type', 'partner_link_enumeration')->exists())->toBeTrue();
});

it('emails the link only to an active referrer and answers every request the same way', function () {
    $referrer = ReferralFixtures::referrer(['email' => 'partner@example.com']);
    ReferralFixtures::referrer(['email' => 'inactive@example.com', 'is_active' => false]);

    $known = $this->from('/partner')->post('/partner', ['email' => 'Partner@Example.com']);
    $unknown = $this->from('/partner')->post('/partner', ['email' => 'stranger@example.com']);
    $inactive = $this->from('/partner')->post('/partner', ['email' => 'inactive@example.com']);

    foreach ([$known, $unknown, $inactive] as $response) {
        $response->assertRedirect(route('partner.request'))
            ->assertSessionHas('status', 'If that address belongs to a referral partner, we have emailed the dashboard link to it. It may take a minute to arrive.');
    }

    $email = EmailMessage::query()->where('template_key', 'partner_dashboard_link')->sole();
    expect($email->to_email)->toBe('partner@example.com')
        ->and($email->order_id)->toBeNull()
        ->and($email->html_body)->toContain(route('partner.dashboard', $referrer->dashboard_token))
        ->and($email->html_body)->toContain($referrer->code);

    $this->get('/partner')->assertOk()->assertSee('Email me my dashboard link')
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

it('rate-limits link requests per email address and per IP', function () {
    ReferralFixtures::referrer(['email' => 'partner@example.com']);

    foreach (range(1, 3) as $i) {
        $this->post('/partner', ['email' => 'partner@example.com'])->assertRedirect();
    }
    $this->post('/partner', ['email' => 'partner@example.com'])->assertStatus(429);

    expect(EmailMessage::query()->where('template_key', 'partner_dashboard_link')->count())->toBe(3);

    // A refused request is not counted; seven more addresses reach the per-IP limit of ten.
    foreach (range(1, 7) as $i) {
        $this->post('/partner', ['email' => "someone{$i}@example.com"])->assertRedirect();
    }
    $this->post('/partner', ['email' => 'another@example.com'])->assertStatus(429);
});
