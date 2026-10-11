<?php

use App\Domain\Referrals\ReferralProgramme;
use App\Models\ReferralVisit;
use App\Models\Service;
use App\Support\Settings;
use Tests\Feature\Referrals\ReferralFixtures;

beforeEach(function () {
    config()->set('statementra.runtime.tasks', []);
    Settings::flush();
});

const REFERRAL_TEST_BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';

it('remembers an active referrer’s code for this visit (session, no cookie) and redirects to the URL without ref', function () {
    $referrer = ReferralFixtures::referrer(['code' => 'AMA10']);

    $response = $this->withHeader('User-Agent', REFERRAL_TEST_BROWSER)->get('/how-it-works?ref=ama10&utm_source=whatsapp');

    $response->assertStatus(302)
        ->assertRedirect(url('/how-it-works').'?utm_source=whatsapp')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertCookieMissing(ReferralProgramme::COOKIE)
        ->assertSessionHas(ReferralProgramme::SESSION_CODE, 'AMA10')
        ->assertSessionHas(ReferralProgramme::SESSION_PROMPT, 'AMA10');

    expect(ReferralVisit::query()->where('referrer_id', $referrer->id)->value('visits'))->toBe(1);
});

it('lets the last link win and uses the cookie lifetime from the settings once the visitor agrees', function () {
    Settings::set('referrals.cookie_days', 7);
    ReferralFixtures::referrer(['code' => 'FIRST']);
    ReferralFixtures::referrer(['code' => 'SECOND', 'email' => 'second@example.com']);

    $this->withHeader('User-Agent', REFERRAL_TEST_BROWSER)->get('/?ref=FIRST')->assertSessionHas(ReferralProgramme::SESSION_CODE, 'FIRST');
    $this->withHeader('User-Agent', REFERRAL_TEST_BROWSER)->get('/?ref=SECOND')->assertSessionHas(ReferralProgramme::SESSION_CODE, 'SECOND');

    $response = $this->postJson(route('referral.consent'), ['decision' => 'remember'])->assertCookie(ReferralProgramme::COOKIE, 'SECOND');
    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === ReferralProgramme::COOKIE);
    expect($cookie->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(7)->getTimestamp() + 5)
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(6)->getTimestamp());
});

it('ignores unknown and inactive codes silently but still cleans the URL', function () {
    ReferralFixtures::referrer(['code' => 'SLEEPY', 'is_active' => false]);

    foreach (['/?ref=NOSUCHCODE', '/?ref=SLEEPY', '/?ref[]=x'] as $url) {
        $response = $this->withHeader('User-Agent', REFERRAL_TEST_BROWSER)->get($url);
        $response->assertRedirect(url('/'))->assertCookieMissing(ReferralProgramme::COOKIE)->assertSessionMissing(ReferralProgramme::SESSION_CODE);
    }

    expect(ReferralVisit::query()->count())->toBe(0);
});

it('counts one visit per visitor per day and does not count crawlers or link previews', function () {
    $referrer = ReferralFixtures::referrer(['code' => 'DAILY']);

    $this->withHeader('User-Agent', REFERRAL_TEST_BROWSER)->get('/?ref=DAILY');
    $this->withHeader('User-Agent', REFERRAL_TEST_BROWSER)->get('/faq?ref=DAILY');
    $this->withHeader('User-Agent', 'WhatsApp/2.23.20.0')->get('/?ref=DAILY');
    $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1)')->get('/?ref=DAILY');

    expect((int) ReferralVisit::query()->where('referrer_id', $referrer->id)->sum('visits'))->toBe(1);

    $this->travel(1)->days();
    $this->withHeader('User-Agent', REFERRAL_TEST_BROWSER)->get('/?ref=DAILY');

    expect(ReferralVisit::query()->where('referrer_id', $referrer->id)->count())->toBe(2)
        ->and((int) ReferralVisit::query()->where('referrer_id', $referrer->id)->sum('visits'))->toBe(2);
});

it('runs before the SEO redirects, keeps one URL per page and never redirects off-site', function () {
    ReferralFixtures::referrer(['code' => 'SEO1']);
    $service = Service::factory()->create(['slug' => 'personal-statement']);

    // Capture first (302 without ref); the slug's own canonical redirect then runs on the clean URL.
    $this->withHeader('User-Agent', REFERRAL_TEST_BROWSER)->get('/services/PERSONAL-STATEMENT?ref=SEO1')
        ->assertStatus(302)->assertRedirect(url('/services/PERSONAL-STATEMENT'))
        ->assertSessionHas(ReferralProgramme::SESSION_CODE, 'SEO1');
    $this->withHeader('User-Agent', REFERRAL_TEST_BROWSER)->get('/services/personal-statement?ref=SEO1&page=2')
        ->assertRedirect(route('services.show', $service->slug).'?page=2');

    // A protocol-relative path must not become a redirect to another host.
    $location = $this->withHeader('User-Agent', REFERRAL_TEST_BROWSER)->get('//about?ref=SEO1')->headers->get('Location');
    expect($location)->toStartWith(url('/'))->not->toStartWith('//');
});

it('does nothing when the referral programme is switched off', function () {
    Settings::set('referrals.enabled', false);
    ReferralFixtures::referrer(['code' => 'OFF1']);

    $this->withHeader('User-Agent', REFERRAL_TEST_BROWSER)->get('/faq?ref=OFF1')
        ->assertOk()
        ->assertCookieMissing(ReferralProgramme::COOKIE);
    expect(ReferralVisit::query()->count())->toBe(0);
});

it('leaves JSON calls alone', function () {
    ReferralFixtures::referrer(['code' => 'POSTED']);

    $this->withHeader('User-Agent', REFERRAL_TEST_BROWSER)->getJson('/?ref=POSTED')->assertCookieMissing(ReferralProgramme::COOKIE);
    expect(ReferralVisit::query()->count())->toBe(0);
});
