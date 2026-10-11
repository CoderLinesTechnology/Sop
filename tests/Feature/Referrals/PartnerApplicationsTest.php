<?php

use App\Domain\Referrals\Tasks\ReferralHousekeeping;
use App\Enums\AdminRole;
use App\Enums\Permission;
use App\Filament\Resources\PartnerApplications\Pages\ListPartnerApplications;
use App\Filament\Resources\PartnerApplications\Pages\ViewPartnerApplication;
use App\Filament\Resources\PartnerApplications\PartnerApplicationResource;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\EmailMessage;
use App\Models\PartnerApplication;
use App\Models\Referrer;
use App\Support\Settings;
use Database\Seeders\ContentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Feature\Referrals\ReferralFixtures;

beforeEach(function () {
    config()->set('statementra.runtime.tasks', []);
    Settings::flush();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function partnerApplicationForm(array $overrides = []): array
{
    return array_merge([
        'full_name' => 'Abena Owusu',
        'email' => 'Abena@Example.com',
        'phone' => '+233 24 123 4567',
        'city' => 'Kumasi',
        'country' => 'GH',
        'channels' => ['whatsapp', 'campus'],
        'channels_details' => 'Final-year WhatsApp groups at KNUST',
        'audience_size' => '500_2000',
        'links' => ['https://instagram.com/abena', '', ''],
        'motivation' => 'Many of my classmates are applying abroad and ask me for help with their statements.',
        'preferred_code' => 'abena',
        'consent' => '1',
    ], $overrides);
}

/** The confirmation link from the last application email. */
function applicationConfirmLink(): string
{
    $html = EmailMessage::query()->where('template_key', 'partner_application_confirm')->latest('id')->firstOrFail()->html_body;
    preg_match('~'.preg_quote(url('/become-a-partner/confirm'), '~').'/([A-Za-z0-9]{48})~', $html, $match);

    return url('/become-a-partner/confirm/'.$match[1]);
}

/** A confirmed application waiting for review. */
function submittedApplication(array $overrides = []): PartnerApplication
{
    test()->post(route('partners.apply.store'), partnerApplicationForm($overrides));
    test()->post(applicationConfirmLink());

    return PartnerApplication::query()->latest('id')->firstOrFail();
}

it('shows the landing copy from the CMS and a form without any payment fields, linked from the footer', function () {
    $this->seed(ContentSeeder::class);

    $html = $this->get(route('partners.apply'))->assertOk()
        ->assertSee('Become a Statementra partner')
        ->assertSee('Track and get paid')
        ->assertSee('name="consent"', false)
        ->assertSee(route('pages.show', 'privacy-policy'), false)
        ->getContent();

    expect($html)->not->toContain('name="payment_method"')
        ->not->toContain('momo_number')
        ->not->toContain('account_number');
    $this->get('/faq')->assertSee(route('partners.apply'), false);

    Settings::set('referrals.applications_open', false);
    $this->get(route('partners.apply'))->assertOk()->assertSee('Applications are closed right now')->assertDontSee('name="consent"', false);
    $this->get('/faq')->assertDontSee(route('partners.apply'), false);
    $this->post(route('partners.apply.store'), partnerApplicationForm())->assertRedirect(route('partners.apply'));
    expect(PartnerApplication::query()->count())->toBe(0);
});

it('needs the applicant to confirm their email before the application counts', function () {
    $this->post(route('partners.apply.store'), partnerApplicationForm())
        ->assertRedirect(route('partners.apply').'#apply')
        ->assertSessionHas('status');

    $application = PartnerApplication::query()->sole();
    expect($application->status)->toBe(PartnerApplication::STATUS_UNCONFIRMED)
        ->and($application->email)->toBe('abena@example.com')
        ->and($application->promotion_channels)->toBe(['whatsapp', 'campus'])
        ->and($application->profile_links)->toBe(['https://instagram.com/abena'])
        ->and($application->preferred_code)->toBe('ABENA')
        ->and($application->consented_at)->not->toBeNull()
        ->and($application->getAttributes())->not->toHaveKey('payment_details');

    $link = applicationConfirmLink();
    $this->get($link)->assertOk()->assertSee('Confirm my application')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($application->refresh()->status)->toBe(PartnerApplication::STATUS_UNCONFIRMED);

    (new RolesAndPermissionsSeeder)->run();
    $manager = AdminUser::factory()->create();
    $manager->assignRole(AdminRole::Finance->value);

    $this->post($link)->assertOk()->assertSee('you’re confirmed', false);
    expect($application->refresh()->status)->toBe(PartnerApplication::STATUS_SUBMITTED)
        ->and($application->confirmed_at)->not->toBeNull()
        ->and(EmailMessage::query()->where('template_key', 'partner_application_received')->where('to_email', 'abena@example.com')->count())->toBe(1)
        ->and($manager->notifications()->count())->toBe(1);

    $this->post($link)->assertSee('This link has expired');
});

it('validates the form and requires consent', function () {
    $this->post(route('partners.apply.store'), partnerApplicationForm([
        'consent' => null, 'channels' => [], 'audience_size' => 'millions', 'links' => ['javascript:alert(1)'], 'motivation' => 'Short',
    ]))->assertSessionHasErrors(['consent', 'channels', 'audience_size', 'links.0', 'motivation']);

    $this->travel(61)->seconds();
    $this->post(route('partners.apply.store'), partnerApplicationForm(['channels' => ['other'], 'channels_details' => '']))
        ->assertSessionHasErrors(['channels_details']);

    expect(PartnerApplication::query()->count())->toBe(0);
});

it('answers bots and repeat submissions the same way without sending more email', function () {
    $this->post(route('partners.apply.store'), partnerApplicationForm(['website' => 'http://spam.example']))
        ->assertSessionHas('status');
    expect(PartnerApplication::query()->count())->toBe(0);

    $this->post(route('partners.apply.store'), partnerApplicationForm())->assertSessionHas('status');
    $this->post(route('partners.apply.store'), partnerApplicationForm())->assertSessionHas('status');

    expect(PartnerApplication::query()->count())->toBe(1)
        ->and(EmailMessage::query()->where('template_key', 'partner_application_confirm')->count())->toBe(1);
});

it('rate-limits applications per IP and per email address', function () {
    foreach (range(1, 3) as $i) {
        $this->post(route('partners.apply.store'), partnerApplicationForm(['email' => "person{$i}@example.com"]))->assertRedirect();
    }
    $this->post(route('partners.apply.store'), partnerApplicationForm(['email' => 'person4@example.com']))->assertStatus(429);

    $this->travel(2)->minutes();
    foreach (range(1, 3) as $i) {
        $this->travel(11)->minutes();
        $this->post(route('partners.apply.store'), partnerApplicationForm(['email' => 'same@example.com']))->assertRedirect();
    }
    $this->travel(11)->minutes();
    $this->post(route('partners.apply.store'), partnerApplicationForm(['email' => 'same@example.com']))->assertStatus(429);
});

it('purges unconfirmed applications after 7 days and rejected ones after the retention period', function () {
    $this->post(route('partners.apply.store'), partnerApplicationForm(['email' => 'never@example.com']));
    $rejected = submittedApplication(['email' => 'rejected@example.com']);
    $rejected->forceFill(['status' => PartnerApplication::STATUS_REJECTED, 'decided_at' => now()])->save();
    $waiting = submittedApplication(['email' => 'waiting@example.com']);

    $this->travel(7)->days();
    $this->travel(1)->hours();
    app(ReferralHousekeeping::class)();
    expect(PartnerApplication::query()->where('email', 'never@example.com')->exists())->toBeFalse()
        ->and($rejected->fresh())->not->toBeNull();

    Settings::set('referrals.rejected_application_days', 30);
    $this->travel(30)->days();
    app(ReferralHousekeeping::class)();
    expect($rejected->fresh())->toBeNull()
        ->and($waiting->fresh())->not->toBeNull();
});

it('lets referral managers approve an application, creating the referrer and emailing both links', function () {
    $admin = actingAsAdmin(AdminRole::Finance);
    $application = submittedApplication();

    Livewire::test(ViewPartnerApplication::class, ['record' => $application->getRouteKey()])
        ->mountAction('approve')
        ->assertActionDataSet(['code' => 'ABENA'])
        ->setActionData(['code' => 'abena-2026', 'commission_rate' => '12.5'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $referrer = Referrer::query()->sole();
    expect($referrer->code)->toBe('ABENA-2026')
        ->and($referrer->email)->toBe('abena@example.com')
        ->and($referrer->name)->toBe('Abena Owusu')
        ->and($referrer->commission_rate_bp)->toBe(1250)
        ->and($referrer->payment_details)->toBeNull()
        ->and($referrer->hasDashboardLink())->toBeTrue()
        ->and($application->refresh()->status)->toBe(PartnerApplication::STATUS_APPROVED)
        ->and($application->referrer_id)->toBe($referrer->id)
        ->and($application->decided_by_admin_id)->toBe($admin->id);

    $email = EmailMessage::query()->where('template_key', 'partner_application_approved')->sole();
    expect($email->to_email)->toBe('abena@example.com')
        ->and($email->html_body)->toContain(route('partner.dashboard', $referrer->dashboard_token))
        ->toContain('ref=ABENA-2026')
        ->toContain('12.5%')
        ->toContain('add your mobile money or bank details');

    $audit = AuditLog::query()->where('action', 'partner_application.approved')->sole();
    expect(json_encode($audit->toArray()))->not->toContain($referrer->dashboard_token);
});

it('refuses an approval code that a referrer or a coupon already uses', function () {
    actingAsAdmin(AdminRole::Finance);
    ReferralFixtures::referrer(['code' => 'ABENA', 'email' => 'other@example.com']);
    Coupon::query()->create(['code' => 'TAKEN', 'percent_off' => 5, 'is_active' => true])->delete();
    $application = submittedApplication();

    // The preferred code is taken, so a free one is suggested.
    Livewire::test(ViewPartnerApplication::class, ['record' => $application->getRouteKey()])
        ->mountAction('approve')
        ->assertActionDataSet(function (array $data): array {
            expect($data['code'])->not->toBe('ABENA')->toStartWith('ABENA');

            return [];
        });

    foreach (['abena', 'taken'] as $code) {
        Livewire::test(ViewPartnerApplication::class, ['record' => $application->getRouteKey()])
            ->callAction('approve', ['code' => $code, 'commission_rate' => '10'])
            ->assertHasActionErrors(['code']);
    }
    expect(Referrer::query()->count())->toBe(1)
        ->and($application->refresh()->status)->toBe(PartnerApplication::STATUS_SUBMITTED);
});

it('rejects an application with an optional reason sent to the applicant', function () {
    actingAsAdmin(AdminRole::Finance);
    $application = submittedApplication();

    Livewire::test(ViewPartnerApplication::class, ['record' => $application->getRouteKey()])
        ->callAction('reject', ['reason' => 'We are only working with campus partners in Accra for now.'])
        ->assertHasNoActionErrors();

    expect($application->refresh()->status)->toBe(PartnerApplication::STATUS_REJECTED)
        ->and($application->rejection_reason)->toBe('We are only working with campus partners in Accra for now.')
        ->and(EmailMessage::query()->where('template_key', 'partner_application_rejected')->sole()->html_body)->toContain('campus partners in Accra')
        ->and(AuditLog::query()->where('action', 'partner_application.rejected')->exists())->toBeTrue();

    $second = submittedApplication(['email' => 'second@example.com']);
    Livewire::test(ViewPartnerApplication::class, ['record' => $second->getRouteKey()])->callAction('reject', ['reason' => '']);
    expect(EmailMessage::query()->where('template_key', 'partner_application_rejected')->latest('id')->first()->html_body)->not->toContain('Our note');
});

it('lists confirmed applications with duplicate warnings, without per-row queries', function () {
    actingAsAdmin(AdminRole::Finance);
    ReferralFixtures::referrer(['email' => 'abena@example.com', 'code' => 'ALREADY']);
    $first = submittedApplication();
    $this->travel(11)->minutes();
    $second = submittedApplication(['email' => 'abena@example.com', 'full_name' => 'Abena O.']);
    $this->post(route('partners.apply.store'), partnerApplicationForm(['email' => 'unconfirmed@example.com']));
    $unconfirmed = PartnerApplication::query()->where('email', 'unconfirmed@example.com')->sole();
    foreach (range(1, 5) as $i) {
        $this->travel(1)->minutes();
        submittedApplication(['email' => "bulk{$i}@example.com"]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ListPartnerApplications::class)
        ->assertCanSeeTableRecords([$first, $second])
        ->assertCanNotSeeTableRecords([$unconfirmed])
        ->filterTable('hide_unconfirmed', false)
        ->assertCanSeeTableRecords([$unconfirmed]);
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => preg_match('/from ["`]partner_applications["`]/', $sql));
    DB::disableQueryLog();
    expect($queries->count())->toBeLessThanOrEqual(6); // page + count, twice, plus the navigation badge

    expect(PartnerApplicationResource::duplicateWarnings(PartnerApplicationResource::getEloquentQuery()->whereKey($second->id)->first()))
        ->toBe(['This email already belongs to a referrer.', 'This email has 1 other application.']);
});

it('keeps applications away from administrators without referral permissions and decisions away from viewers', function () {
    $application = submittedApplication();

    actingAsAdmin(AdminRole::Operations);
    $this->get(PartnerApplicationResource::getUrl('index'))->assertForbidden();

    $this->flushSession();
    (new RolesAndPermissionsSeeder)->run();
    Role::create(['name' => 'referral_viewer', 'guard_name' => 'admin'])->givePermissionTo(Permission::ReferralsView);
    $viewer = AdminUser::factory()->create();
    $viewer->assignRole('referral_viewer');
    $this->actingAs($viewer, 'admin');

    $this->get(PartnerApplicationResource::getUrl('view', ['record' => $application]))->assertOk()->assertSee('Abena Owusu');
    Livewire::test(ViewPartnerApplication::class, ['record' => $application->getRouteKey()])
        ->assertActionHidden('approve')
        ->assertActionHidden('reject')
        ->assertActionHidden('deleteApplication');
});

it('deletes an application on an erasure request and audits it without personal data', function () {
    actingAsAdmin(AdminRole::Finance);
    $application = submittedApplication();

    Livewire::test(ViewPartnerApplication::class, ['record' => $application->getRouteKey()])
        ->callAction('deleteApplication');

    expect(PartnerApplication::query()->count())->toBe(0);
    $audit = AuditLog::query()->where('action', 'partner_application.deleted')->sole();
    expect(json_encode($audit->toArray()))->not->toContain('abena@example.com')->not->toContain('Abena Owusu');
});
