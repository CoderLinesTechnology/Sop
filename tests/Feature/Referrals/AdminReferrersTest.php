<?php

use App\Domain\Referrals\ReferralCommissions;
use App\Enums\AdminRole;
use App\Enums\Permission;
use App\Filament\Pages\Settings as SettingsPage;
use App\Filament\Resources\Coupons\Pages\CreateCoupon;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Referrers\Pages\CreateReferrer;
use App\Filament\Resources\Referrers\Pages\EditReferrer;
use App\Filament\Resources\Referrers\Pages\ListReferrers;
use App\Filament\Resources\Referrers\Pages\ViewReferrer;
use App\Filament\Resources\Referrers\ReferrerResource;
use App\Filament\Resources\Referrers\RelationManagers\CommissionsRelationManager;
use App\Filament\Resources\Referrers\RelationManagers\ReferredOrdersRelationManager;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\EmailMessage;
use App\Models\ReferralCommission;
use App\Models\ReferralVisit;
use App\Models\Referrer;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Feature\Referrals\ReferralFixtures;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    config()->set('statementra.runtime.tasks', []);
    Settings::flush();
});

/** A referrer with two unpaid ledger entries (an earning and a refund reversal) and one already paid. */
function referrerWithLedger(): array
{
    $referrer = ReferralFixtures::referrer(['code' => 'LEDGER', 'commission_rate_bp' => 1000]);
    $commissions = app(ReferralCommissions::class);
    $entries = collect([
        ReferralFixtures::paidReferredOrder($referrer, 10000),
        ReferralFixtures::paidReferredOrder($referrer, 30000),
        ReferralFixtures::paidReferredOrder($referrer, 50000),
    ])->map(fn ($order) => $commissions->recordForPayment($order->payments()->sole()));

    $paid = $entries->last();
    $paid->forceFill(['paid_at' => now()->subDay(), 'payout_reference' => 'OLD-1'])->save();

    return [$referrer, $entries];
}

it('gives referrers to finance and super admins only', function () {
    $referrer = ReferralFixtures::referrer();

    actingAsAdmin(AdminRole::Operations);
    $this->get(ReferrerResource::getUrl('index'))->assertForbidden();
    $this->get(ReferrerResource::getUrl('edit', ['record' => $referrer]))->assertForbidden();

    $this->flushSession();
    actingAsAdmin(AdminRole::Content);
    $this->get(ReferrerResource::getUrl('index'))->assertForbidden();

    $this->flushSession();
    actingAsAdmin(AdminRole::Finance);
    $this->get(ReferrerResource::getUrl('index'))->assertOk()->assertSee($referrer->code);
    $this->get(ReferrerResource::getUrl('edit', ['record' => $referrer]))->assertOk();

    expect(AdminRole::Finance->permissions())->toContain(Permission::ReferralsView, Permission::ReferralsManage)
        ->and(Permission::all())->toContain('referrals.view', 'referrals.manage');
});

it('lets a view-only administrator see referrers and their ledger but change nothing', function () {
    (new RolesAndPermissionsSeeder)->run();
    Role::create(['name' => 'referral_viewer', 'guard_name' => 'admin'])->givePermissionTo(Permission::ReferralsView);
    $viewer = AdminUser::factory()->create();
    $viewer->assignRole('referral_viewer');
    $this->actingAs($viewer, 'admin');
    [$referrer, $entries] = referrerWithLedger();

    $this->get(ReferrerResource::getUrl('view', ['record' => $referrer]))->assertOk();
    $this->get(ReferrerResource::getUrl('edit', ['record' => $referrer]))->assertForbidden();
    $this->get(ReferrerResource::getUrl('create'))->assertForbidden();

    Livewire::test(CommissionsRelationManager::class, ['ownerRecord' => $referrer, 'pageClass' => ViewReferrer::class])
        ->assertCanSeeTableRecords($entries->all());

    // Payment details are for payout administrators only.
    $referrer->forceFill(['payment_details' => ['method' => 'momo', 'network' => 'mtn', 'number' => '0241234567', 'account_name' => 'Kofi Mensah']])->save();
    Livewire::test(ViewReferrer::class, ['record' => $referrer->getRouteKey()])
        ->assertSee('MTN MoMo •••• 4567, K. Mensah')
        ->assertDontSee('0241234567')
        ->assertActionHidden('paymentDetails');

    expect(ReferralCommission::query()->whereNull('paid_at')->count())->toBe(2);
});

it('creates a referrer with a percentage stored in basis points, a normalised code and a dashboard link, and audits it without the token', function () {
    actingAsAdmin(AdminRole::Finance);

    Livewire::test(CreateReferrer::class)
        ->fillForm([
            'name' => 'Esi Boateng',
            'email' => 'Esi@Example.COM',
            'phone' => '+233 24 000 0000',
            'code' => ' esi-2026 ',
            'commission_rate_bp' => '12.5',
            'is_active' => true,
            'notes' => 'Agreed by phone.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $referrer = Referrer::query()->sole();
    expect($referrer->code)->toBe('ESI-2026')
        ->and($referrer->email)->toBe('esi@example.com')
        ->and($referrer->commission_rate_bp)->toBe(1250)
        ->and($referrer->public_id)->toHaveLength(26)
        ->and($referrer->dashboard_token)->toHaveLength(48)
        ->and($referrer->dashboard_token_hash)->toBe(hash('sha256', $referrer->dashboard_token));

    $audit = AuditLog::query()->where('action', 'referrer.created')->sole();
    expect($audit->after)->toHaveKey('commission_rate_bp', 1250)
        ->not->toHaveKey('dashboard_token')
        ->not->toHaveKey('dashboard_token_hash')
        ->and(json_encode($audit->toArray()))->not->toContain($referrer->dashboard_token);

    Livewire::test(EditReferrer::class, ['record' => $referrer->getRouteKey()])
        ->assertSchemaStateSet(['commission_rate_bp' => '12.5', 'code' => 'ESI-2026']);
});

it('rejects commission rates outside 0–100% or with more than two decimals', function (string $rate) {
    actingAsAdmin(AdminRole::Finance);

    Livewire::test(CreateReferrer::class)
        ->fillForm(['name' => 'Rate Test', 'email' => 'rate@example.com', 'code' => 'RATE1', 'commission_rate_bp' => $rate])
        ->call('create')
        ->assertHasFormErrors(['commission_rate_bp']);

    expect(Referrer::query()->count())->toBe(0);
})->with(['101', '12.345', '-1', 'ten']);

it('keeps referral codes and coupon codes apart, in both directions', function () {
    actingAsAdmin(AdminRole::Finance);
    Coupon::query()->create(['code' => 'SAVE10', 'percent_off' => 10, 'is_active' => true])->delete();
    ReferralFixtures::referrer(['code' => 'KWAME']);

    Livewire::test(CreateReferrer::class)
        ->fillForm(['name' => 'Clash', 'email' => 'clash@example.com', 'code' => 'save10', 'commission_rate_bp' => '10'])
        ->call('create')
        ->assertHasFormErrors(['code']);

    Livewire::test(CreateReferrer::class)
        ->fillForm(['name' => 'Twin', 'email' => 'twin@example.com', 'code' => 'kwame', 'commission_rate_bp' => '10'])
        ->call('create')
        ->assertHasFormErrors(['code']);

    Livewire::test(CreateCoupon::class)
        ->fillForm(['code' => 'Kwame', 'discount_type' => 'percent', 'percent_off' => 5])
        ->call('create')
        ->assertHasFormErrors(['code']);

    expect(Referrer::query()->count())->toBe(1)
        ->and(Coupon::withTrashed()->count())->toBe(1);
});

it('audits edits to a referrer', function () {
    actingAsAdmin(AdminRole::Finance);
    $referrer = ReferralFixtures::referrer(['commission_rate_bp' => 1000]);

    Livewire::test(EditReferrer::class, ['record' => $referrer->getRouteKey()])
        ->fillForm(['commission_rate_bp' => '15'])
        ->call('save')
        ->assertHasNoFormErrors();

    $audit = AuditLog::query()->where('action', 'referrer.updated')->sole();
    expect($audit->before)->toBe(['commission_rate_bp' => 1000])
        ->and($audit->after)->toBe(['commission_rate_bp' => 1500]);
});

it('lists referrers with visits, paid orders, revenue and balance due from aggregate queries', function () {
    actingAsAdmin(AdminRole::Finance);
    [$referrer] = referrerWithLedger();
    ReferralVisit::query()->insert(['referrer_id' => $referrer->id, 'date' => now()->toDateString(), 'visits' => 42]);
    foreach (range(1, 5) as $i) {
        ReferralFixtures::referrer(['email' => "r{$i}@example.com"]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ListReferrers::class)
        ->assertCanSeeTableRecords([$referrer])
        ->assertSee('42')
        ->assertSee('10%');
    $referrerQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => preg_match('/from ["`]referrers["`]/', $sql));
    DB::disableQueryLog();

    // One page query (plus the count), whatever the number of referrers: no per-row queries.
    expect($referrerQueries->count())->toBeLessThanOrEqual(3);

    $row = Referrer::query()->whereKey($referrer->id)
        ->withSum(['commissions as due_total' => fn ($q) => $q->whereNull('paid_at')], 'amount')
        ->first();
    expect((int) $row->due_total)->toBe(4000); // 10% of 100.00 + 300.00 unpaid
});

it('shows the referred orders read-only', function () {
    actingAsAdmin(AdminRole::Finance);
    $referrer = ReferralFixtures::referrer(['email' => 'self@example.com']);
    $order = ReferralFixtures::paidReferredOrder($referrer);
    $self = ReferralFixtures::paidReferredOrder($referrer, 5000, ['email' => 'self@example.com']);

    Livewire::test(ReferredOrdersRelationManager::class, ['ownerRecord' => $referrer, 'pageClass' => EditReferrer::class])
        ->assertCanSeeTableRecords([$order, $self])
        ->assertSee($order->reference)
        ->assertSee('Self-referral: no commission');
});

it('replaces, emails and disables the dashboard link, all audited without the token', function () {
    actingAsAdmin(AdminRole::Finance);
    $referrer = ReferralFixtures::referrer();
    $oldToken = $referrer->dashboard_token;

    Livewire::test(EditReferrer::class, ['record' => $referrer->getRouteKey()])
        ->callAction('replaceDashboardLink')
        ->assertNotified('New dashboard link created');

    $newToken = $referrer->refresh()->dashboard_token;
    expect($newToken)->not->toBe($oldToken);
    $this->get('/partner/'.$oldToken)->assertNotFound();
    $this->get('/partner/'.$newToken)->assertOk();

    Livewire::test(EditReferrer::class, ['record' => $referrer->getRouteKey()])
        ->callAction('sendDashboardLink')
        ->assertNotified('Dashboard link emailed');
    expect(EmailMessage::query()->where('template_key', 'partner_dashboard_link')->where('to_email', $referrer->email)->count())->toBe(1);

    Livewire::test(EditReferrer::class, ['record' => $referrer->getRouteKey()])
        ->callAction('disableDashboardLink');
    expect($referrer->refresh()->dashboard_token_hash)->toBeNull();
    $this->get('/partner/'.$newToken)->assertNotFound();

    $audits = AuditLog::query()->where('action', 'like', 'referrer.dashboard_link_%')->orderBy('id')->pluck('action')->all();
    expect($audits)->toBe(['referrer.dashboard_link_replaced', 'referrer.dashboard_link_sent', 'referrer.dashboard_link_disabled'])
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain($oldToken)->not->toContain($newToken);
});

it('deactivates a referrer and erases a referrer’s personal data but keeps the ledger', function () {
    actingAsAdmin(AdminRole::Finance);
    [$referrer, $entries] = referrerWithLedger();

    Livewire::test(ListReferrers::class)
        ->callAction(TestAction::make('deactivate')->table($referrer));
    expect($referrer->refresh()->is_active)->toBeFalse();

    Livewire::test(EditReferrer::class, ['record' => $referrer->getRouteKey()])
        ->callAction('erasePersonalData')
        ->assertNotified('Personal data erased');

    $referrer->refresh();
    expect($referrer->name)->toBe('Erased referrer')
        ->and($referrer->email)->toBeNull()
        ->and($referrer->phone)->toBeNull()
        ->and($referrer->notes)->toBeNull()
        ->and($referrer->erased_at)->not->toBeNull()
        ->and($referrer->dashboard_token_hash)->toBeNull()
        ->and(ReferralCommission::query()->where('referrer_id', $referrer->id)->count())->toBe($entries->count())
        ->and(AuditLog::query()->whereIn('action', ['referrer.deactivated', 'referrer.erased'])->count())->toBe(2);

    // The ledger stays visible for the erased referrer.
    Livewire::test(CommissionsRelationManager::class, ['ownerRecord' => $referrer, 'pageClass' => EditReferrer::class])
        ->assertCanSeeTableRecords($entries->all());
});

it('shows who referred an order on the order page', function () {
    actingAsAdmin(AdminRole::Finance);
    $referrer = ReferralFixtures::referrer(['code' => 'ORDERPAGE']);
    $order = ReferralFixtures::paidReferredOrder($referrer);

    Livewire::test(ViewOrder::class, ['record' => $order->public_id])
        ->assertSee('Referred by Kofi Mensah (ORDERPAGE)')
        ->assertSee('Code typed at checkout');
});

it('saves the referral settings on the settings page', function () {
    actingAsAdmin();

    Livewire::test(SettingsPage::class)
        ->fillForm(['referrals.enabled' => false, 'referrals.cookie_days' => 45])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Settings::get('referrals.enabled'))->toBeFalse()
        ->and(Settings::get('referrals.cookie_days'))->toBe(45);

    Livewire::test(SettingsPage::class)
        ->fillForm(['referrals.cookie_days' => 400])
        ->call('save')
        ->assertHasFormErrors(['referrals.cookie_days']);

    // Payout and application settings; the minimum is entered in GH₵ and stored in minor units.
    Livewire::test(SettingsPage::class)
        ->assertSchemaStateSet(['referrals.minimum_payout' => '100.00', 'referrals.hold_days' => 14])
        ->fillForm(['referrals.cookie_days' => 30, 'referrals.minimum_payout' => '150.50', 'referrals.hold_days' => 10, 'referrals.applications_open' => false, 'referrals.rejected_application_days' => 90])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Settings::get('referrals.minimum_payout'))->toBe(15050)
        ->and(Settings::get('referrals.hold_days'))->toBe(10)
        ->and(Settings::get('referrals.applications_open'))->toBeFalse()
        ->and(Settings::get('referrals.rejected_application_days'))->toBe(90);

    Livewire::test(SettingsPage::class)
        ->fillForm(['referrals.minimum_payout' => '0', 'referrals.rejected_application_days' => 1])
        ->call('save')
        ->assertHasFormErrors(['referrals.minimum_payout', 'referrals.rejected_application_days']);
});
