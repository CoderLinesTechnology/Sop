<?php

use App\Domain\Referrals\PaymentDetails;
use App\Domain\Referrals\Tasks\ReferralHousekeeping;
use App\Enums\AdminRole;
use App\Filament\Resources\ReferralPayouts\Pages\ViewReferralPayout;
use App\Filament\Resources\ReferralPayouts\ReferralPayoutResource;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\ReferralPayout;
use App\Models\Referrer;
use App\Support\Settings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Referrals\ReferralFixtures;

beforeEach(function () {
    config()->set('statementra.runtime.tasks', []);
    Settings::flush();
});

function momoForm(array $overrides = []): array
{
    return array_merge([
        'payment_method' => 'momo',
        'momo_network' => 'mtn',
        'momo_number' => '024 555 1234',
        'account_name' => 'Kofi Mensah',
    ], $overrides);
}

/** The confirmation link from the last payment-details email. */
function detailsConfirmLink(): string
{
    $html = EmailMessage::query()->where('template_key', 'partner_payment_details_confirm')->latest('id')->firstOrFail()->html_body;
    preg_match('~'.preg_quote(url('/partner/details'), '~').'/([A-Za-z0-9]{48})~', $html, $match);

    return url('/partner/details/'.$match[1]);
}

it('applies a first-time addition only after the partner confirms it from their email', function () {
    $referrer = ReferralFixtures::referrer();
    $dashboard = '/partner/'.$referrer->dashboard_token;

    $this->get($dashboard)->assertSee('Add your payment details')->assertSee('Add your mobile money or bank details');

    $this->post(route('partner.details.change', $referrer->dashboard_token), momoForm())
        ->assertRedirect($dashboard.'#payment-details')
        ->assertSessionHas('partner_status');

    $referrer->refresh();
    expect($referrer->payment_details)->toBeNull()
        ->and($referrer->pending_payment_details)->toBe(['method' => 'momo', 'network' => 'mtn', 'number' => '024 555 1234', 'account_name' => 'Kofi Mensah']);
    $this->get($dashboard)->assertSee('Waiting for your confirmation')->assertSee('MTN MoMo •••• 1234, K. Mensah');

    $email = EmailMessage::query()->where('template_key', 'partner_payment_details_confirm')->sole();
    expect($email->to_email)->toBe('kofi@example.com')
        ->and($email->html_body)->toContain('MTN MoMo •••• 1234, K. Mensah')->not->toContain('024 555 1234');

    // Opening the link (as a mail scanner would) changes nothing; only the button does.
    $link = detailsConfirmLink();
    $this->get($link)->assertOk()->assertSee('Confirm these details')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($referrer->refresh()->payment_details)->toBeNull();

    $this->post($link)->assertOk()->assertSee('Payment details confirmed');
    $referrer->refresh();
    expect($referrer->payment_details)->toBe(['method' => 'momo', 'network' => 'mtn', 'number' => '024 555 1234', 'account_name' => 'Kofi Mensah'])
        ->and($referrer->payment_details_changed_at)->not->toBeNull()
        ->and($referrer->pending_payment_details)->toBeNull()
        ->and($referrer->pending_details_token_hash)->toBeNull();

    // Single use.
    $this->post($link)->assertOk()->assertSee('This link has expired');
    $this->get($dashboard)->assertSee('Payouts are sent to')->assertSee('MTN MoMo •••• 1234, K. Mensah')->assertDontSee('024 555 1234');

    expect(AuditLog::query()->whereIn('action', ['referrer.payment_details_change_requested', 'referrer.payment_details_changed'])->count())->toBe(2)
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain('555 1234');
});

it('keeps the current details until a change is confirmed, and expires unconfirmed changes', function () {
    $referrer = ReferralFixtures::referrer();
    $current = ['method' => 'momo', 'network' => 'telecel', 'number' => '020 000 1111', 'account_name' => 'Kofi Mensah'];
    $referrer->forceFill(['payment_details' => $current, 'payment_details_changed_at' => now()->subMonth()])->save();

    $this->post(route('partner.details.change', $referrer->dashboard_token), [
        'payment_method' => 'bank', 'bank_name' => 'GCB Bank', 'account_number' => '1234567890', 'account_name' => 'Attacker Name',
    ])->assertRedirect();
    expect($referrer->refresh()->payment_details)->toBe($current);
    $link = detailsConfirmLink();

    $this->travel(49)->hours();
    $this->get($link)->assertSee('This link has expired');
    $this->post($link)->assertSee('This link has expired');
    expect($referrer->refresh()->payment_details)->toBe($current);

    app(ReferralHousekeeping::class)();
    expect($referrer->refresh()->pending_payment_details)->toBeNull();
});

it('validates payment details and limits how often a change can be requested', function () {
    $referrer = ReferralFixtures::referrer();
    $route = route('partner.details.change', $referrer->dashboard_token);

    $this->post($route, ['payment_method' => 'momo', 'account_name' => 'K'])
        ->assertSessionHasErrors(['momo_network', 'momo_number', 'account_name']);
    $this->post($route, ['payment_method' => 'bank', 'account_name' => 'Kofi Mensah', 'bank_name' => 'GCB', 'account_number' => '<script>'])
        ->assertSessionHasErrors(['account_number']);
    $this->post($route, momoForm(['momo_network' => 'unknown']))->assertSessionHasErrors(['momo_network']);
    $this->travel(61)->seconds(); // past the per-minute route limit; the per-partner limit is what is tested next

    foreach (range(1, 3) as $i) {
        $this->post($route, momoForm())->assertSessionHasNoErrors();
    }
    $this->post($route, momoForm())->assertSessionHasErrors('payment_method');
    expect(EmailMessage::query()->where('template_key', 'partner_payment_details_confirm')->count())->toBe(3);
});

it('masks payment details for every audience except payout administrators', function () {
    expect(PaymentDetails::mask(['method' => 'momo', 'network' => 'mtn', 'number' => '024 123 4567', 'account_name' => 'Ama Serwaa Mensah']))->toBe('MTN MoMo •••• 4567, A. Mensah')
        ->and(PaymentDetails::mask(['method' => 'bank', 'bank_name' => 'GCB Bank', 'account_number' => '0012345678', 'account_name' => 'Kofi']))->toBe('GCB Bank •••• 5678, K.')
        ->and(PaymentDetails::mask(null))->toBeNull();
});

it('warns administrators when payment details changed shortly before a payout request', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAsAdmin(AdminRole::Finance);
    $referrer = ReferralFixtures::referrer();
    $referrer->forceFill(['payment_details' => ['method' => 'momo', 'network' => 'mtn', 'number' => '0241234567', 'account_name' => 'Kofi Mensah'], 'payment_details_changed_at' => now()->subDays(3)])->save();
    $payout = ReferralPayout::query()->forceCreate([
        'referrer_id' => $referrer->id, 'amount' => 15000, 'currency' => 'GHS', 'status' => 'requested',
        'payment_details' => $referrer->payment_details, 'requested_at' => now(),
    ]);

    expect(ReferralPayoutResource::detailsWarning($payout->load('referrer')))->toContain('within 14 days');
    Livewire::test(ViewReferralPayout::class, ['record' => $payout->getRouteKey()])
        ->assertSee('Check before paying')
        ->assertSee('within 14 days of this request');

    Referrer::query()->whereKey($referrer->id)->update(['payment_details_changed_at' => now()->subDays(40)]);
    expect(ReferralPayoutResource::detailsWarning($payout->refresh()->load('referrer')))->toBeNull();

    // A change after the request is flagged too (the request keeps its snapshot).
    Referrer::query()->whereKey($referrer->id)->update(['payment_details_changed_at' => now()->addHour()]);
    expect(ReferralPayoutResource::detailsWarning($payout->refresh()->load('referrer')))->toContain('after this request');
});
