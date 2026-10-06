<?php

use App\Enums\AdminRole;
use App\Models\AdminUser;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case
|--------------------------------------------------------------------------
|
| Feature tests run against a real MySQL test database (see phpunit.xml;
| override with DB_DATABASE=... to run suites in parallel without clashes).
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Storage::fake('private');
        Storage::fake('public');
    })
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** Create an administrator with the given role (MFA already configured) and sign in. */
function actingAsAdmin(AdminRole $role = AdminRole::SuperAdmin): AdminUser
{
    (new RolesAndPermissionsSeeder)->run();

    $admin = AdminUser::factory()->create();
    $admin->assignRole($role->value);

    test()->actingAs($admin, 'admin');

    return $admin;
}
