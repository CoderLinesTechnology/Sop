<?php

use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Payments\RefundService;
use App\Domain\Referrals\ReferralBalances;
use App\Domain\Referrals\ReferralCommissions;
use App\Domain\Referrals\ReferralPayouts;
use App\Domain\Referrals\ReferralRefused;
use App\Domain\Referrals\Tasks\ReferralHousekeeping;
use App\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\ReferralCommission;
use App\Models\ReferralPayout;
use App\Models\Referrer;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Referrals\ReferralFixtures;

beforeEach(function () {
    config()->set('statementra.runtime.tasks', []);
    Settings::flush();
    $this->mock(PipelineDispatcher::class)->shouldReceive('startForOrder', 'cancel');
});

const MOMO_DETAILS = ['method' => 'momo', 'network' => 'mtn', 'number' => '024 123 4567', 'account_name' => 'Kofi Mensah'];

/** A referrer at 10% with confirmed mobile money details. */
function payoutReferrer(array $attributes = []): Referrer
{
    $referrer = ReferralFixtures::referrer(['commission_rate_bp' => 1000] + $attributes);
    $referrer->forceFill(['payment_details' => MOMO_DETAILS, 'payment_details_changed_at' => now()->subMonths(2)])->save();

    return $referrer;
}

/** Record a GHS commission of 10% of $paid, $daysAgo days ago. */
function earn(Referrer $referrer, int $paid, int $daysAgo = 20): ReferralCommission
{
    test()->travelTo(now()->subDays($daysAgo));
    $order = ReferralFixtures::paidReferredOrder($referrer, $paid, ['currency' => 'GHS']);
    $entry = app(ReferralCommissions::class)->recordForPayment($order->payments()->sole());
    test()->travelBack();

    return $entry;
}

function dashboardOf(Referrer $referrer): string
{
    return '/partner/'.$referrer->dashboard_token;
}

it('keeps earnings pending for the hold period and makes them available exactly when it ends', function () {
    $referrer = payoutReferrer();
    $entry = ReferralFixtures::paidReferredOrder($referrer, 50000, ['currency' => 'GHS']);
    app(ReferralCommissions::class)->recordForPayment($entry->payments()->sole());
    $balances = app(ReferralBalances::class);

    $this->travel(14)->days();
    $this->travel(-1)->seconds();
    expect($balances->inCurrency($referrer, 'GHS'))->toMatchArray(['pending' => 5000, 'available' => 0]);
    expect($balances->inCurrency($referrer, 'GHS')['next_available_at']->toDateString())->toBe(now()->addSecond()->toDateString());

    $this->travel(1)->seconds();
    expect($balances->inCurrency($referrer, 'GHS'))->toMatchArray(['pending' => 0, 'available' => 5000]);

    Settings::set('referrals.hold_days', 30);
    expect($balances->inCurrency($referrer, 'GHS'))->toMatchArray(['pending' => 5000, 'available' => 0]);
});

it('enables the payout button only from the minimum, saying how much is left to go', function () {
    $referrer = payoutReferrer();
    earn($referrer, 60000); // GHS 60.00 commission

    $this->get(dashboardOf($referrer))->assertOk()
        ->assertSee('You can request a payout once your available balance reaches GH₵100.00 (GH₵40.00 to go).', false)
        ->assertSee('disabled aria-describedby="payout-help"', false)
        ->assertDontSee(route('partner.payouts.request', $referrer->dashboard_token), false);

    $this->post(route('partner.payouts.request', $referrer->dashboard_token))->assertSessionHasErrors('payout');
    expect(ReferralPayout::query()->count())->toBe(0);

    earn($referrer, 40000); // + GHS 40.00 = exactly the minimum
    $this->get(dashboardOf($referrer))
        ->assertSee(route('partner.payouts.request', $referrer->dashboard_token), false)
        ->assertSee('Request payout of GH₵100.00');
});

it('blocks payouts until payment details are confirmed', function () {
    $referrer = ReferralFixtures::referrer(['commission_rate_bp' => 1000]);
    earn($referrer, 200000);

    $this->get(dashboardOf($referrer))->assertSee('Add your payment details to request a payout.');
    expect(fn () => app(ReferralPayouts::class)->request($referrer))->toThrow(ReferralRefused::class, 'Add your payment details');

    // A pending (unconfirmed) change does not count.
    $referrer->forceFill(['pending_payment_details' => MOMO_DETAILS, 'pending_details_token_hash' => hash('sha256', str_repeat('a', 48)), 'pending_details_expires_at' => now()->addDay()])->save();
    expect(fn () => app(ReferralPayouts::class)->request($referrer->refresh()))->toThrow(ReferralRefused::class);
    expect(ReferralPayout::query()->count())->toBe(0);
});

it('requests the whole available balance once, whatever the number of clicks', function () {
    $referrer = payoutReferrer();
    $a = earn($referrer, 80000);
    $b = earn($referrer, 70000);
    $pending = earn($referrer, 50000, daysAgo: 2);

    $this->post(route('partner.payouts.request', $referrer->dashboard_token))->assertRedirect(dashboardOf($referrer).'#payouts');
    $this->post(route('partner.payouts.request', $referrer->dashboard_token))->assertRedirect();

    $payout = ReferralPayout::query()->sole();
    expect($payout->amount)->toBe(15000)
        ->and($payout->currency)->toBe('GHS')
        ->and($payout->status)->toBe(ReferralPayout::STATUS_REQUESTED)
        ->and($payout->payment_details)->toBe(MOMO_DETAILS)
        ->and($a->refresh()->payout_id)->toBe($payout->id)
        ->and($b->refresh()->payout_id)->toBe($payout->id)
        ->and($pending->refresh()->payout_id)->toBeNull();

    expect(app(ReferralBalances::class)->inCurrency($referrer, 'GHS'))->toMatchArray(['available' => 0, 'requested' => 15000, 'pending' => 5000]);

    // The database also refuses a second open request.
    expect(fn () => ReferralPayout::query()->forceCreate(['referrer_id' => $referrer->id, 'amount' => 1, 'currency' => 'GHS', 'status' => 'requested']))
        ->toThrow(UniqueConstraintViolationException::class);

    $email = EmailMessage::query()->where('template_key', 'partner_payout_requested')->sole();
    expect($email->to_email)->toBe('kofi@example.com')
        ->and($email->html_body)->toContain('GH₵150.00')->toContain('MTN MoMo •••• 4567, K. Mensah')
        ->and(AuditLog::query()->where('action', 'referrer.payout_requested')->exists())->toBeTrue();
});

it('notifies payout administrators of a new request', function () {
    (new RolesAndPermissionsSeeder)->run();
    $finance = AdminUser::factory()->create();
    $finance->assignRole(AdminRole::Finance->value);
    $content = AdminUser::factory()->create();
    $content->assignRole(AdminRole::Content->value);
    $referrer = payoutReferrer();
    earn($referrer, 200000);

    app(ReferralPayouts::class)->request($referrer);

    expect($finance->notifications()->count())->toBe(1)
        ->and($content->notifications()->count())->toBe(0)
        ->and($finance->notifications()->first()->data['title'])->toContain($referrer->code);
});

it('stamps the entries paid and deducts them when the payout is paid', function () {
    $admin = AdminUser::factory()->create();
    $referrer = payoutReferrer();
    $entries = [earn($referrer, 100000), earn($referrer, 50000)];
    $payout = app(ReferralPayouts::class)->request($referrer);

    app(ReferralPayouts::class)->markPaid($payout, 'MOMO-998877', $admin);

    expect($payout->refresh()->status)->toBe(ReferralPayout::STATUS_PAID)
        ->and($payout->payout_reference)->toBe('MOMO-998877')
        ->and($payout->decided_by_admin_id)->toBe($admin->id);
    foreach ($entries as $entry) {
        expect($entry->refresh()->paid_at)->not->toBeNull()
            ->and($entry->payout_reference)->toBe('MOMO-998877')
            ->and($entry->paid_by_admin_id)->toBe($admin->id);
    }
    expect(app(ReferralBalances::class)->inCurrency($referrer, 'GHS'))->toMatchArray(['available' => 0, 'requested' => 0, 'paid' => 15000])
        ->and(EmailMessage::query()->where('template_key', 'partner_payout_paid')->sole()->html_body)->toContain('MOMO-998877')
        ->and(AuditLog::query()->where('action', 'referral_payout.paid')->sole()->admin_user_id)->toBe($admin->id);

    expect(fn () => app(ReferralPayouts::class)->markPaid($payout, 'AGAIN', $admin))->toThrow(ReferralRefused::class);
});

it('releases the entries when a request is rejected or cancelled', function () {
    $admin = AdminUser::factory()->create();
    $referrer = payoutReferrer();
    $entry = earn($referrer, 150000);
    $payouts = app(ReferralPayouts::class);

    $first = $payouts->request($referrer);
    $payouts->reject($first, 'The number did not match the account name.', $admin);
    expect($first->refresh()->status)->toBe(ReferralPayout::STATUS_REJECTED)
        ->and($entry->refresh()->payout_id)->toBeNull()
        ->and(app(ReferralBalances::class)->inCurrency($referrer, 'GHS')['available'])->toBe(15000)
        ->and(EmailMessage::query()->where('template_key', 'partner_payout_rejected')->sole()->html_body)->toContain('The number did not match the account name.');

    $second = $payouts->request($referrer);
    $this->post(route('partner.payouts.cancel', [$referrer->dashboard_token, $second->public_id]))->assertRedirect(dashboardOf($referrer).'#payouts');
    expect($second->refresh()->status)->toBe(ReferralPayout::STATUS_CANCELLED)
        ->and($entry->refresh()->payout_id)->toBeNull();

    // Another referrer's request cannot be cancelled with this dashboard.
    $other = payoutReferrer(['email' => 'other@example.com', 'code' => 'OTHERREF']);
    earn($other, 150000);
    $theirs = $payouts->request($other);
    $this->post(route('partner.payouts.cancel', [$referrer->dashboard_token, $theirs->public_id]))->assertNotFound();
    expect($theirs->refresh()->status)->toBe(ReferralPayout::STATUS_REQUESTED);
});

it('keeps a late refund reversal out of the open request and takes it from the next balance, which may go negative', function () {
    $admin = AdminUser::factory()->create();
    $referrer = payoutReferrer();
    $this->travelTo(now()->subDays(20));
    $order = ReferralFixtures::paidReferredOrder($referrer, 150000, ['currency' => 'GHS']);
    app(ReferralCommissions::class)->recordForPayment($order->payments()->sole());
    $this->travelBack();

    $payout = app(ReferralPayouts::class)->request($referrer);
    expect($payout->amount)->toBe(15000);

    // The order is refunded in full after the request.
    Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 5, 'status' => 'processed']])]);
    $refunds = app(RefundService::class);
    $refunds->approve($refunds->request($order->refresh(), 150000, 'Refund', $admin), $admin);

    $reversal = ReferralCommission::query()->where('kind', ReferralCommission::KIND_REFUND_REVERSAL)->sole();
    expect($reversal->amount)->toBe(-15000)->and($reversal->payout_id)->toBeNull()
        ->and($payout->refresh()->amount)->toBe(15000);

    app(ReferralPayouts::class)->markPaid($payout, 'MOMO-1', $admin);
    expect(app(ReferralBalances::class)->inCurrency($referrer, 'GHS'))->toMatchArray(['available' => -15000, 'paid' => 15000]);

    // A negative balance blocks requests until new earnings cover it.
    earn($referrer, 200000);
    expect(app(ReferralBalances::class)->inCurrency($referrer, 'GHS')['available'])->toBe(5000);
    expect(fn () => app(ReferralPayouts::class)->request($referrer))->toThrow(ReferralRefused::class);
    $this->get(dashboardOf($referrer))->assertSee('(GH₵50.00 to go)', false);
});

it('shows balances and payout history on the dashboard without customer data', function () {
    $referrer = payoutReferrer();
    $order = ReferralFixtures::paidReferredOrder($referrer, 100000, ['currency' => 'GHS', 'customer_name' => 'Efua Hidden', 'email' => 'efua.hidden@example.com']);
    $this->travelTo(now()->subDays(20));
    app(ReferralCommissions::class)->recordForPayment($order->payments()->sole());
    $this->travelBack();
    earn($referrer, 100000);
    earn($referrer, 30000, daysAgo: 3);
    $payout = app(ReferralPayouts::class)->request($referrer);
    app(ReferralPayouts::class)->markPaid($payout, 'MOMO-42', AdminUser::factory()->create());

    $html = $this->get(dashboardOf($referrer))->assertOk()->getContent();

    expect($html)
        ->toMatch('~Pending</dt>\s*<dd[^>]*>GH₵30</dd>~u')
        ->toMatch('~Paid</dt>\s*<dd[^>]*>GH₵200</dd>~u')
        ->toContain('Next available '.now()->subDays(3)->addDays(14)->format('j M Y'))
        ->toContain('GH₵200.00')->toContain('MOMO-42')
        ->toContain('MTN MoMo •••• 4567, K. Mensah')
        ->not->toContain('024 123 4567')
        ->not->toContain('Efua Hidden')->not->toContain('efua.hidden@example.com')
        ->not->toContain($order->reference)->not->toContain($order->public_id);
});

it('keeps payout and partner records for the record period, then removes the personal data and keeps the amounts', function () {
    $admin = AdminUser::factory()->create();
    $ended = payoutReferrer();
    earn($ended, 200000);
    $old = app(ReferralPayouts::class)->request($ended);
    app(ReferralPayouts::class)->markPaid($old, 'MOMO-OLD', $admin);
    $ended->forceFill(['is_active' => false])->save();

    $active = payoutReferrer();
    earn($active, 200000);
    $open = app(ReferralPayouts::class)->request($active);

    // Five years on: nothing is removed yet.
    $this->travel(5)->years();
    app(ReferralHousekeeping::class)();
    expect($old->fresh()->payment_details)->not->toBeNull()
        ->and($ended->fresh()->isErased())->toBeFalse();

    // Past six years: the old payout loses where it was paid; the partner who left is erased; amounts stay.
    $this->travel(1)->years();
    $this->travel(1)->days();
    app(ReferralHousekeeping::class)();

    expect($old->fresh()->payment_details)->toBeNull()
        ->and($old->fresh()->amount)->toBe(20000)
        ->and($ended->fresh()->isErased())->toBeTrue()
        ->and($ended->fresh()->email)->toBeNull()
        ->and($ended->commissions()->sum('amount'))->toBe(20000)
        ->and(AuditLog::query()->where('action', 'referrer.erased')->sole()->meta['source'])->toBe('retention')
        // An active partner and an open request are never touched.
        ->and($active->fresh()->isErased())->toBeFalse()
        ->and($open->fresh()->payment_details)->not->toBeNull();

    // A shorter period set by the administrator applies too.
    Settings::set('referrals.financial_record_years', 1);
    $recent = payoutReferrer(['is_active' => false]);
    $this->travel(2)->years();
    app(ReferralHousekeeping::class)();
    expect($recent->fresh()->isErased())->toBeTrue()
        ->and($active->fresh()->isErased())->toBeFalse();
});
