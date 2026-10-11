<?php

use App\Domain\Referrals\ReferralCommissions;
use App\Domain\Referrals\ReferralPayouts;
use App\Enums\AdminRole;
use App\Enums\Permission;
use App\Filament\Resources\ReferralPayouts\Pages\ListReferralPayouts;
use App\Filament\Resources\ReferralPayouts\Pages\ViewReferralPayout;
use App\Filament\Resources\ReferralPayouts\ReferralPayoutResource;
use App\Filament\Resources\Referrers\Pages\EditReferrer;
use App\Filament\Resources\Referrers\RelationManagers\PayoutsRelationManager;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\ReferralPayout;
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
    config()->set('statementra.runtime.tasks', []);
    Settings::flush();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** An open payout request of GHS 150.00 from a referrer with confirmed mobile money details. */
function openPayout(array $referrerAttributes = []): ReferralPayout
{
    $referrer = ReferralFixtures::referrer(['commission_rate_bp' => 1000] + $referrerAttributes);
    $referrer->forceFill([
        'payment_details' => ['method' => 'momo', 'network' => 'mtn', 'number' => '024 777 8899', 'account_name' => $referrer->name],
        'payment_details_changed_at' => now()->subMonths(3),
    ])->save();

    test()->travelTo(now()->subDays(20));
    $order = ReferralFixtures::paidReferredOrder($referrer, 150000, ['currency' => 'GHS']);
    app(ReferralCommissions::class)->recordForPayment($order->payments()->sole());
    test()->travelBack();

    return app(ReferralPayouts::class)->request($referrer->refresh());
}

it('gives payout requests to referral administrators and decisions to payout administrators only', function () {
    $payout = openPayout();

    actingAsAdmin(AdminRole::Operations);
    $this->get(ReferralPayoutResource::getUrl('index'))->assertForbidden();

    $this->flushSession();
    (new RolesAndPermissionsSeeder)->run();
    Role::create(['name' => 'referral_viewer', 'guard_name' => 'admin'])->givePermissionTo(Permission::ReferralsView);
    $viewer = AdminUser::factory()->create();
    $viewer->assignRole('referral_viewer');
    $this->actingAs($viewer, 'admin');

    $this->get(ReferralPayoutResource::getUrl('index'))->assertOk();
    Livewire::test(ViewReferralPayout::class, ['record' => $payout->getRouteKey()])
        ->assertSee('MTN MoMo •••• 8899, K. Mensah')
        ->assertDontSee('024 777 8899')
        ->assertActionHidden('markPaid')
        ->assertActionHidden('reject')
        ->assertActionHidden('revealDetails');

    expect(AdminRole::Finance->permissions())->toContain(Permission::ReferralsPayouts)
        ->and(Permission::all())->toContain('referrals.payouts');
});

it('reveals the full payment details to payout administrators and audits it', function () {
    $admin = actingAsAdmin(AdminRole::Finance);
    $payout = openPayout();

    $page = Livewire::test(ViewReferralPayout::class, ['record' => $payout->getRouteKey()])->mountAction('revealDetails');

    // Modal contents are not in the component HTML: read the mounted action's schema.
    $schema = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName());
    $shown = collect($schema->getFlatComponents(withHidden: true))->map(fn ($component) => method_exists($component, 'getState') ? $component->getState() : null)->filter()->values()->all();
    expect($shown)->toContain('024 777 8899', 'Mobile money', 'MTN MoMo');

    $audit = AuditLog::query()->where('action', 'referral_payout.details_revealed')->sole();
    expect($audit->admin_user_id)->toBe($admin->id)
        ->and(json_encode($audit->toArray()))->not->toContain('777 8899');
});

it('marks a payout paid with the transfer reference, emails the partner and audits it', function () {
    $admin = actingAsAdmin(AdminRole::Finance);
    $payout = openPayout();

    Livewire::test(ViewReferralPayout::class, ['record' => $payout->getRouteKey()])
        ->callAction('markPaid', ['payout_reference' => ''])
        ->assertHasActionErrors(['payout_reference' => 'required']);
    expect($payout->refresh()->status)->toBe(ReferralPayout::STATUS_REQUESTED);

    Livewire::test(ListReferralPayouts::class)
        ->assertCanSeeTableRecords([$payout])
        ->callAction(TestAction::make('markPaid')->table($payout), ['payout_reference' => 'MOMO-55501'])
        ->assertHasNoActionErrors()
        ->assertNotified('Payout recorded');

    expect($payout->refresh()->status)->toBe(ReferralPayout::STATUS_PAID)
        ->and($payout->payout_reference)->toBe('MOMO-55501')
        ->and($payout->decided_by_admin_id)->toBe($admin->id)
        ->and($payout->commissions()->whereNull('paid_at')->count())->toBe(0)
        ->and(EmailMessage::query()->where('template_key', 'partner_payout_paid')->sole()->html_body)->toContain('MOMO-55501')
        ->and(AuditLog::query()->where('action', 'referral_payout.paid')->sole()->after['payout_reference'])->toBe('MOMO-55501');
});

it('rejects a payout with a reason and makes the amount available again', function () {
    actingAsAdmin(AdminRole::Finance);
    $payout = openPayout();

    Livewire::test(ViewReferralPayout::class, ['record' => $payout->getRouteKey()])
        ->callAction('reject', ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    Livewire::test(ViewReferralPayout::class, ['record' => $payout->getRouteKey()])
        ->callAction('reject', ['reason' => 'The MoMo name does not match.'])
        ->assertHasNoActionErrors();

    expect($payout->refresh()->status)->toBe(ReferralPayout::STATUS_REJECTED)
        ->and($payout->commissions()->count())->toBe(0)
        ->and(EmailMessage::query()->where('template_key', 'partner_payout_rejected')->sole()->html_body)->toContain('The MoMo name does not match.')
        ->and(AuditLog::query()->where('action', 'referral_payout.rejected')->exists())->toBeTrue();
});

it('lists payout requests with their partner in a bounded number of queries', function () {
    actingAsAdmin(AdminRole::Finance);
    $payouts = collect(range(1, 6))->map(fn (int $i) => openPayout(['email' => "partner{$i}@example.com"]));

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ListReferralPayouts::class)->assertCanSeeTableRecords($payouts->all());
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql) => preg_match('/from ["`]referrers["`]/', $sql))->count())->toBeLessThanOrEqual(2)
        ->and($queries->filter(fn (string $sql) => preg_match('/from ["`]referral_payouts["`]/', $sql))->count())->toBeLessThanOrEqual(3);
});

it('shows a referrer’s payout requests on their page and refuses erasure while one is open', function () {
    actingAsAdmin(AdminRole::Finance);
    $payout = openPayout();
    $referrer = $payout->referrer;

    Livewire::test(PayoutsRelationManager::class, ['ownerRecord' => $referrer, 'pageClass' => EditReferrer::class])
        ->assertCanSeeTableRecords([$payout]);

    Livewire::test(EditReferrer::class, ['record' => $referrer->getRouteKey()])
        ->callAction('erasePersonalData')
        ->assertNotified("Couldn't erase the referrer");
    expect(Referrer::query()->whereKey($referrer->id)->value('erased_at'))->toBeNull();

    app(ReferralPayouts::class)->reject($payout, 'Closing the account.', AdminUser::factory()->create());
    Livewire::test(EditReferrer::class, ['record' => $referrer->getRouteKey()])
        ->callAction('erasePersonalData')
        ->assertNotified('Personal data erased');
    $referrer->refresh();
    expect($referrer->erased_at)->not->toBeNull()
        ->and($referrer->payment_details)->toBeNull()
        ->and($payout->refresh()->payment_details)->not->toBeNull(); // the payout record keeps its snapshot (accounting)
});
