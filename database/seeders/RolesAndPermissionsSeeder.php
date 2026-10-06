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
            $existing = Role::query()->where(['name' => $role->value, 'guard_name' => 'admin'])->first();

            if ($role === AdminRole::SuperAdmin) {
                // Super administrators always hold every permission, including new ones.
                ($existing ?? Role::create(['name' => $role->value, 'guard_name' => 'admin']))->syncPermissions(P::all());
            } elseif (! $existing) {
                // Defaults are applied once; later edits in Admin → Roles are kept on re-seed
                // (the Roles page offers "Reset to defaults").
                Role::create(['name' => $role->value, 'guard_name' => 'admin'])->syncPermissions($role->permissions());
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
