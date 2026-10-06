<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the first super administrator. In production set ADMIN_EMAIL and
 * ADMIN_PASSWORD (or use `php artisan statementra:create-admin`); a random
 * password is generated and printed otherwise. MFA setup is enforced on the
 * first sign-in.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', app()->isProduction() ? null : 'admin@statementra.test');
        if (! $email) {
            $this->command?->warn('ADMIN_EMAIL not set; skipping admin creation. Run `php artisan statementra:create-admin`.');

            return;
        }

        $existing = AdminUser::query()->where('email', $email)->first();
        if ($existing) {
            $existing->assignRole(AdminRole::SuperAdmin->value);

            return;
        }

        $password = env('ADMIN_PASSWORD') ?: (app()->isProduction() ? Str::password(20) : 'Statementra!Admin2026');

        $admin = AdminUser::query()->create([
            'name' => env('ADMIN_NAME', 'Statementra Admin'),
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);
        $admin->assignRole(AdminRole::SuperAdmin->value);

        if (! env('ADMIN_PASSWORD')) {
            $this->command?->info("Admin created: {$email} / {$password} (you will be asked to set up MFA on first sign-in)");
        }
    }
}
