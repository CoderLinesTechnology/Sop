<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Enums\Permission as P;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Idempotent: creates every permission and syncs each role to its definition. */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (P::all() as $name) {
            Permission::findOrCreate($name, 'admin');
        }

        foreach (AdminRole::cases() as $role) {
            Role::findOrCreate($role->value, 'admin')->syncPermissions($role->permissions());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
