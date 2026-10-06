<?php

use App\Enums\AdminRole;
use App\Enums\Permission;
use App\Filament\Pages\Settings as SettingsPage;
use App\Filament\Resources\AdminUsers\AdminUserResource;
use App\Filament\Resources\AdminUsers\Pages\CreateAdminUser;
use App\Filament\Resources\AdminUsers\Pages\EditAdminUser;
use App\Filament\Resources\AdminUsers\Pages\ListAdminUsers;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Support\Catalogue\SystemHealth;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\SystemTask;
use App\Support\Audit;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Settings::flush();
});

it('renders the system screens for a super admin', function () {
    $admin = actingAsAdmin();
    Audit::log('settings.updated', 'settings', ['a' => 1], ['a' => 2]);
    $log = AuditLog::query()->first();
    $role = Role::findByName(AdminRole::Content->value, 'admin');
    $super = Role::findByName(AdminRole::SuperAdmin->value, 'admin');

    $this->get(AdminUserResource::getUrl('index'))->assertOk()->assertSee($admin->email);
    $this->get(AdminUserResource::getUrl('create'))->assertOk();
    $this->get(AdminUserResource::getUrl('edit', ['record' => $admin]))->assertOk()->assertDontSee('JBSWY3DPEHPK3PXP');
    $this->get(RoleResource::getUrl('index'))->assertOk()->assertSee('Content Admin');
    $this->get(RoleResource::getUrl('edit', ['record' => $role]))->assertOk();
    $this->get(RoleResource::getUrl('view', ['record' => $super]))->assertOk();
    $this->get(AuditLogResource::getUrl('index'))->assertOk()->assertSee('settings.updated');
    $this->get(AuditLogResource::getUrl('view', ['record' => $log]))->assertOk();
});

it('creates administrators with roles and a strong password', function () {
    actingAsAdmin();

    Livewire::test(CreateAdminUser::class)
        ->fillForm(['name' => 'Efua Mensah', 'email' => 'Efua@Statementra.test', 'password' => 'short', 'password_confirmation' => 'short', 'roles' => ['content_admin']])
        ->call('create')
        ->assertHasFormErrors(['password']);

    Livewire::test(CreateAdminUser::class)
        ->fillForm(['name' => 'Efua Mensah', 'email' => 'Efua@Statementra.test', 'password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026', 'roles' => []])
        ->call('create')
        ->assertHasFormErrors(['roles']);

    Livewire::test(CreateAdminUser::class)
        ->fillForm(['name' => 'Efua Mensah', 'email' => 'Efua@Statementra.test', 'password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026', 'roles' => ['content_admin', 'finance_admin'], 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = AdminUser::query()->where('email', 'efua@statementra.test')->firstOrFail();
    expect($created->hasRole('content_admin'))->toBeTrue()
        ->and($created->hasRole('finance_admin'))->toBeTrue()
        ->and(Hash::check('Str0ng!Passw0rd#2026', $created->password))->toBeTrue()
        ->and($created->hasMfaEnabled())->toBeFalse();

    $audit = AuditLog::query()->where('action', 'admin.created')->sole();
    expect($audit->after['roles'])->toBe(['content_admin', 'finance_admin'])
        ->and(json_encode($audit->after))->not->toContain('Str0ng');
});

it('never removes the last active super admin', function () {
    $me = actingAsAdmin();

    // Removing my own super admin role would leave nobody in charge.
    Livewire::test(EditAdminUser::class, ['record' => $me->getRouteKey()])
        ->fillForm(['roles' => ['content_admin']])
        ->call('save')
        ->assertHasFormErrors(['roles']);
    expect($me->fresh()->hasRole('super_admin'))->toBeTrue();

    // Another super admin exists → now allowed.
    $other = AdminUser::factory()->create();
    $other->assignRole('super_admin');

    Livewire::test(EditAdminUser::class, ['record' => $other->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($other->fresh()->is_active)->toBeFalse();

    // With the other super admin deactivated, I am the last one again.
    Livewire::test(EditAdminUser::class, ['record' => $me->getRouteKey()])
        ->fillForm(['roles' => ['ai_admin']])
        ->call('save')
        ->assertHasFormErrors(['roles']);
    expect($me->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('protects the last super admin even when another administrator edits it', function () {
    (new RolesAndPermissionsSeeder)->run();
    $last = AdminUser::factory()->create(['name' => 'Founder']);
    $last->assignRole('super_admin');

    // An administrator who manages admins but is not a super admin.
    Role::findOrCreate('admins_only', 'admin')->givePermissionTo(Permission::AdminsManage);
    $manager = AdminUser::factory()->create();
    $manager->assignRole('admins_only');
    $this->actingAs($manager, 'admin');

    Livewire::test(EditAdminUser::class, ['record' => $last->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    expect($last->fresh()->is_active)->toBeTrue();
});

it('does not let administrators deactivate themselves', function () {
    $me = actingAsAdmin();
    $other = AdminUser::factory()->create();
    $other->assignRole('super_admin');

    Livewire::test(EditAdminUser::class, ['record' => $me->getRouteKey()])
        ->assertFormFieldDisabled('is_active')
        ->fillForm(['is_active' => false, 'name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    $me->refresh();
    expect($me->is_active)->toBeTrue()->and($me->name)->toBe('Renamed');
});

it('resets another administrator\'s two-factor authentication', function () {
    $me = actingAsAdmin();
    $other = AdminUser::factory()->create();
    $other->assignRole('content_admin');
    expect($other->hasMfaEnabled())->toBeTrue();

    Livewire::test(ListAdminUsers::class)
        ->assertActionHidden(TestAction::make('resetMfa')->table($me))
        ->callAction(TestAction::make('resetMfa')->table($other));

    $other->refresh();
    expect($other->hasMfaEnabled())->toBeFalse()
        ->and($other->app_authentication_recovery_codes)->toBeNull();

    $audit = AuditLog::query()->where('action', 'admin.mfa_reset')->sole();
    expect($audit->target_id)->toBe((string) $other->id)
        ->and($audit->admin_user_id)->toBe($me->id);
});

it('edits role permissions with an audit trail and protects the super admin role', function () {
    actingAsAdmin();
    $content = Role::findByName(AdminRole::Content->value, 'admin');
    $super = Role::findByName(AdminRole::SuperAdmin->value, 'admin');

    $editor = AdminUser::factory()->create();
    $editor->assignRole('content_admin');
    expect($editor->checkPermissionTo(Permission::PricingManage))->toBeFalse();

    Livewire::test(EditRole::class, ['record' => $content->getRouteKey()])
        ->fillForm(['permissions' => [...AdminRole::Content->permissions(), Permission::PricingManage]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($editor->fresh()->checkPermissionTo(Permission::PricingManage))->toBeTrue();
    $audit = AuditLog::query()->where('action', 'role.permissions_updated')->sole();
    expect($audit->meta['added'])->toBe([Permission::PricingManage])
        ->and($audit->meta['removed'])->toBe([]);

    Livewire::test(EditRole::class, ['record' => $content->getRouteKey()])
        ->callAction('resetToDefaults');
    expect($editor->fresh()->checkPermissionTo(Permission::PricingManage))->toBeFalse();

    Livewire::test(EditRole::class, ['record' => $super->getRouteKey()])->assertForbidden();
});

it('keeps the audit log read-only and filterable', function () {
    $admin = actingAsAdmin();
    Audit::log('service.updated', 'Service', ['price' => 1], ['price' => 2]);
    Audit::log('prompt.activated', 'PromptVersion', null, ['active_version' => 3]);

    Livewire::test(ListAuditLogs::class)
        ->assertCanSeeTableRecords(AuditLog::all())
        ->filterTable('action', ['prompt.activated'])
        ->assertCanSeeTableRecords(AuditLog::query()->where('action', 'prompt.activated')->get())
        ->assertCanNotSeeTableRecords(AuditLog::query()->where('action', 'service.updated')->get());

    expect(AuditLogResource::canCreate())->toBeFalse()
        ->and(AuditLogResource::canEdit(AuditLog::query()->first()))->toBeFalse()
        ->and(AuditLogResource::canDelete(AuditLog::query()->first()))->toBeFalse();
});

it('restricts system screens to the right permissions', function (AdminRole $role) {
    actingAsAdmin($role);
    $this->withoutVite();

    $this->get(AdminUserResource::getUrl('index'))->assertForbidden();
    $this->get(RoleResource::getUrl('index'))->assertForbidden();
    $this->get(AuditLogResource::getUrl('index'))->assertForbidden();
})->with([AdminRole::Content, AdminRole::Finance, AdminRole::Operations, AdminRole::Ai]);

it('shows the heartbeat URL, regenerates it without logging the token and lists maintenance tasks', function () {
    config(['statementra.runtime.heartbeat_token' => null]);
    $admin = actingAsAdmin();

    $original = SystemHealth::pingUrl();
    expect($original)->toStartWith(url('/system/heartbeat/'));

    Livewire::test(SettingsPage::class)
        ->assertSchemaStateSet(['system_ping_url' => $original])
        ->callAction(TestAction::make('regenerateHeartbeatToken')->schemaComponent('heartbeat'))
        ->assertSchemaStateSet(fn (): array => ['system_ping_url' => SystemHealth::pingUrl()]);

    Settings::flush();
    $token = Settings::get(SystemHealth::TOKEN_KEY);
    expect(SystemHealth::pingUrl())->toBe(url('/system/heartbeat/'.$token))
        ->and(SystemHealth::pingUrl())->not->toBe($original);

    $audit = AuditLog::query()->where('action', 'settings.heartbeat_token_regenerated')->sole();
    expect(json_encode([$audit->before, $audit->after]))->not->toContain($token)
        ->and($audit->admin_user_id)->toBe($admin->id);

    $this->get(SettingsPage::getUrl())
        ->assertOk()
        ->assertSee('No heartbeat recorded yet')
        ->assertSee('payments.process-events')
        ->assertSee('Never run');
});

it('hides token regeneration when the token comes from the environment', function () {
    config(['statementra.runtime.heartbeat_token' => 'from-env-token']);
    actingAsAdmin();

    expect(SystemHealth::pingUrl())->toBe(url('/system/heartbeat/from-env-token'));

    // A hidden schema-component action is not resolvable at all, so it cannot be mounted or called.
    Livewire::test(SettingsPage::class)
        ->assertSchemaStateSet(['system_ping_url' => url('/system/heartbeat/from-env-token')])
        ->assertActionDoesNotExist(TestAction::make('regenerateHeartbeatToken')->schemaComponent('heartbeat'))
        ->assertDontSee('Regenerate URL');
});

it('runs a maintenance task on demand and audits it', function () {
    $admin = actingAsAdmin();

    Livewire::test(SettingsPage::class)
        ->callAction(TestAction::make('runSystemTask')->schemaComponent('maintenance'), ['task' => 'orders.prune-drafts'])
        ->assertHasNoActionErrors()
        ->assertNotified('Task started');

    $task = SystemTask::query()->where('name', 'orders.prune-drafts')->sole();
    expect((int) $task->run_count)->toBe(1)
        ->and($task->last_finished_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'system.task_run')->where('admin_user_id', $admin->id)->exists())->toBeTrue();
});
