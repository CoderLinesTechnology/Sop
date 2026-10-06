<?php

use App\Enums\AdminRole;
use App\Filament\Pages\Settings as SettingsPage;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Support\Settings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Settings::flush();
});

it('shows the settings page to super admins only', function () {
    actingAsAdmin();
    $this->get(SettingsPage::getUrl())
        ->assertOk()
        ->assertSee('Webhook URL')
        ->assertSee('Configured')
        ->assertDontSee((string) config('statementra.paystack.secret_key'))
        ->assertDontSee((string) config('statementra.paystack.public_key'));

    Livewire::test(SettingsPage::class)
        ->assertSchemaStateSet(['paystack_webhook_url' => url('/webhooks/paystack')]);
});

it('saves changed settings only and audits before and after', function () {
    $admin = actingAsAdmin();

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'general.site_name' => 'Statementra Pro',
            'orders.delivery_min_minutes' => 25,
            'orders.delivery_max_minutes' => 40,
            'orders.allowed_file_types' => ['pdf', 'docx'],
            'ai.banned_phrases' => ['delve', 'tapestry', 'synergy'],
            'ai.daily_budget_usd' => 120.5,
            'email.admin_notification_emails' => ['ops@statementra.test'],
            'payments.allow_free_orders' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Settings saved');

    expect(Settings::get('general.site_name'))->toBe('Statementra Pro')
        ->and(Settings::get('orders.delivery_min_minutes'))->toBe(25)
        ->and(Settings::get('orders.allowed_file_types'))->toBe(['pdf', 'docx'])
        ->and(Settings::get('ai.banned_phrases'))->toBe(['delve', 'tapestry', 'synergy'])
        ->and(Settings::get('ai.daily_budget_usd'))->toBe(120.5)
        ->and(Settings::get('payments.allow_free_orders'))->toBeFalse()
        ->and(Settings::adminNotificationEmails())->toBe(['ops@statementra.test']);

    // Untouched settings keep following the code defaults.
    expect(SiteSetting::query()->where('key', 'general.tagline')->exists())->toBeFalse()
        ->and(SiteSetting::query()->where('key', 'general.site_name')->value('updated_by_admin_id'))->toBe($admin->id);

    $audit = AuditLog::query()->where('action', 'settings.updated')->sole();
    expect($audit->admin_user_id)->toBe($admin->id)
        ->and($audit->before['general.site_name'])->toBe('Statementra')
        ->and($audit->after['general.site_name'])->toBe('Statementra Pro')
        ->and($audit->after)->not->toHaveKey('general.tagline');
});

it('validates settings', function () {
    actingAsAdmin();

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'general.support_email' => 'not-an-email',
            'orders.delivery_min_minutes' => 40,
            'orders.delivery_max_minutes' => 20,
            'email.admin_notification_emails' => ['nope'],
        ])
        ->call('save')
        ->assertHasFormErrors(['general.support_email', 'orders.delivery_max_minutes', 'email.admin_notification_emails.0']);

    expect(AuditLog::query()->where('action', 'settings.updated')->exists())->toBeFalse();
});

it('forbids settings to administrators without settings.manage', function (AdminRole $role) {
    actingAsAdmin($role);

    $this->withoutVite()->get(SettingsPage::getUrl())->assertForbidden();
    Livewire::test(SettingsPage::class)->assertForbidden();
})->with([AdminRole::Content, AdminRole::Finance, AdminRole::Operations, AdminRole::Ai]);

it('warns when Paystack live mode uses a test key', function () {
    config(['statementra.paystack.mode' => 'live', 'statementra.paystack.secret_key' => 'sk_test_x', 'statementra.paystack.public_key' => 'pk_test_x']);
    expect(SettingsPage::paystackLiveWithTestKey())->toBeTrue();

    config(['statementra.paystack.secret_key' => 'sk_live_x', 'statementra.paystack.public_key' => 'pk_live_x']);
    expect(SettingsPage::paystackLiveWithTestKey())->toBeFalse();
});
