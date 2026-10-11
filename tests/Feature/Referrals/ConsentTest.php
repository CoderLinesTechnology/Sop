<?php

use App\Domain\Referrals\ReferralProgramme;
use App\Models\Order;
use App\Models\Service;
use App\Support\Settings;
use Tests\Feature\Referrals\ReferralFixtures;

beforeEach(function () {
    config()->set('statementra.runtime.tasks', []);
    Settings::flush();
});

const CONSENT_TEST_BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15';

function consentPrompt(): string
{
    return 'partner referred you';
}

function referralOrderForm(Service $service): array
{
    return ['answers' => [
        'full_name' => 'Ama Owusu',
        'email' => 'ama@example.com',
        'institution' => 'University of Ghana',
        'programme' => 'MSc Data Science',
        'country' => 'GH',
        'background' => str_repeat('I studied statistics and worked as an analyst for two years. ', 4),
    ]];
}

it('keeps a followed link in the session only and asks once whether to remember it', function () {
    ReferralFixtures::referrer(['code' => 'ASKME']);

    $this->withHeader('User-Agent', CONSENT_TEST_BROWSER)->get('/faq?ref=ASKME')
        ->assertRedirect(url('/faq'))
        ->assertCookieMissing(ReferralProgramme::COOKIE)
        ->assertSessionHas(ReferralProgramme::SESSION_CODE, 'ASKME');

    $page = $this->withHeader('User-Agent', CONSENT_TEST_BROWSER)->get('/faq')->assertOk();
    $page->assertSee(consentPrompt())
        ->assertSee('May we remember this for 30 days', false)
        ->assertSee(route('pages.show', 'privacy-policy'), false)
        ->assertSee('action="'.route('referral.consent').'"', false)
        ->assertCookieMissing(ReferralProgramme::COOKIE);

    // Once per session per code: neither a reload nor the same link again shows it.
    $this->withHeader('User-Agent', CONSENT_TEST_BROWSER)->get('/faq')->assertDontSee(consentPrompt());
    $this->withHeader('User-Agent', CONSENT_TEST_BROWSER)->get('/faq?ref=ASKME');
    $this->withHeader('User-Agent', CONSENT_TEST_BROWSER)->get('/faq')->assertDontSee(consentPrompt());

    // A different partner's link is asked about again.
    ReferralFixtures::referrer(['code' => 'OTHER', 'email' => 'other@example.com']);
    $this->withHeader('User-Agent', CONSENT_TEST_BROWSER)->get('/faq?ref=OTHER');
    $this->withHeader('User-Agent', CONSENT_TEST_BROWSER)->get('/faq')->assertSee(consentPrompt());
});

it('sets the 30-day cookie only when the visitor agrees', function () {
    ReferralFixtures::referrer(['code' => 'REMEMBER']);
    $this->withHeader('User-Agent', CONSENT_TEST_BROWSER)->get('/faq?ref=REMEMBER');

    $response = $this->withHeader('User-Agent', CONSENT_TEST_BROWSER)
        ->postJson(route('referral.consent'), ['decision' => 'remember'])
        ->assertNoContent();

    $response->assertCookie(ReferralProgramme::COOKIE, 'REMEMBER');
    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === ReferralProgramme::COOKIE);
    expect($cookie->isHttpOnly())->toBeTrue()
        ->and(strtolower((string) $cookie->getSameSite()))->toBe('lax')
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp())
        ->and($cookie->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(30)->getTimestamp() + 5);

    // Without JavaScript the form posts normally and returns to the page it was on.
    $this->withHeader('User-Agent', CONSENT_TEST_BROWSER)->get('/faq');
    $this->post(route('referral.consent'), ['decision' => 'remember'])
        ->assertStatus(303)
        ->assertRedirect(url('/faq'))
        ->assertCookie(ReferralProgramme::COOKIE, 'REMEMBER');
});

it('keeps the referral for this visit only when the visitor declines', function () {
    $referrer = ReferralFixtures::referrer(['code' => 'DECLINED']);
    $service = Service::factory()->withStandardFields()->create(['price' => 8900, 'currency' => 'USD']);
    $this->withHeader('User-Agent', CONSENT_TEST_BROWSER)->get('/faq?ref=DECLINED');

    $this->postJson(route('referral.consent'), ['decision' => 'decline'])
        ->assertNoContent()
        ->assertCookieMissing(ReferralProgramme::COOKIE);

    // Same visit: the order is still attributed (session).
    $this->post("/start/{$service->slug}", referralOrderForm($service))->assertRedirect();
    $order = Order::query()->sole();
    expect($order->referrer_id)->toBe($referrer->id)
        ->and($order->referral_source)->toBe('link');
});

it('attributes a later visit only through the cookie the visitor agreed to', function () {
    $referrer = ReferralFixtures::referrer(['code' => 'LATER']);
    $service = Service::factory()->withStandardFields()->create(['price' => 8900, 'currency' => 'USD']);
    $this->withHeader('User-Agent', CONSENT_TEST_BROWSER)->get('/faq?ref=LATER');

    // A new visit (new session) without the cookie: no attribution.
    $this->flushSession();
    $this->post("/start/{$service->slug}", referralOrderForm($service))->assertRedirect();
    expect(Order::query()->latest('id')->first()->referrer_id)->toBeNull();

    // A new visit with the consented cookie: attributed.
    $this->flushSession();
    $this->withCookie(ReferralProgramme::COOKIE, 'LATER')
        ->post("/start/{$service->slug}", array_replace_recursive(referralOrderForm($service), ['answers' => ['email' => 'kojo@example.com']]))
        ->assertRedirect();
    expect(Order::query()->latest('id')->first()->referrer_id)->toBe($referrer->id);
});

it('lets the link of this visit win over an older remembered one', function () {
    ReferralFixtures::referrer(['code' => 'OLDONE']);
    $current = ReferralFixtures::referrer(['code' => 'NEWONE', 'email' => 'new@example.com']);
    $service = Service::factory()->withStandardFields()->create(['price' => 8900, 'currency' => 'USD']);

    $this->withCookie(ReferralProgramme::COOKIE, 'OLDONE')->withHeader('User-Agent', CONSENT_TEST_BROWSER)->get('/faq?ref=NEWONE');
    $this->withCookie(ReferralProgramme::COOKIE, 'OLDONE')->post("/start/{$service->slug}", referralOrderForm($service))->assertRedirect();

    expect(Order::query()->sole()->referrer_id)->toBe($current->id);
});

it('respects Global Privacy Control: no prompt and no cookie, session only', function () {
    $referrer = ReferralFixtures::referrer(['code' => 'PRIVATE']);
    $service = Service::factory()->withStandardFields()->create(['price' => 8900, 'currency' => 'USD']);
    $gpc = ['User-Agent' => CONSENT_TEST_BROWSER, 'Sec-GPC' => '1'];

    $this->withHeaders($gpc)->get('/faq?ref=PRIVATE')->assertCookieMissing(ReferralProgramme::COOKIE);
    $this->withHeaders($gpc)->get('/faq')->assertOk()->assertDontSee(consentPrompt());

    $this->withHeaders($gpc)->postJson(route('referral.consent'), ['decision' => 'remember'])
        ->assertNoContent()
        ->assertCookieMissing(ReferralProgramme::COOKIE);

    $this->withHeaders($gpc)->post("/start/{$service->slug}", referralOrderForm($service))->assertRedirect();
    expect(Order::query()->sole()->referrer_id)->toBe($referrer->id);
});

it('does not prompt again when the cookie already holds the code, and answers only valid decisions', function () {
    ReferralFixtures::referrer(['code' => 'KNOWN']);

    $this->withCookie(ReferralProgramme::COOKIE, 'KNOWN')->withHeader('User-Agent', CONSENT_TEST_BROWSER)->get('/faq?ref=KNOWN');
    $this->withCookie(ReferralProgramme::COOKIE, 'KNOWN')->get('/faq')->assertDontSee(consentPrompt());

    $this->postJson(route('referral.consent'), ['decision' => 'always'])->assertStatus(422);
    // Nothing to remember without a referral in this visit.
    $this->flushSession();
    $this->postJson(route('referral.consent'), ['decision' => 'remember'])->assertNoContent()->assertCookieMissing(ReferralProgramme::COOKIE);
});
