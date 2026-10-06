<?php

use App\Enums\AdminRole;
use App\Models\AdminUser;
use App\Support\Audit;
use App\Support\Runtime\Heartbeat;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/*
| Statementra needs no cron: maintenance runs on a heartbeat driven by site
| traffic (see App\Support\Runtime\Heartbeat). This command runs it by hand.
*/

Artisan::command('statementra:heartbeat {--task= : Run one task now, ignoring its interval} {--budget=50 : Seconds to spend}', function (Heartbeat $heartbeat) {
    if ($task = $this->option('task')) {
        $this->info($task.': '.$heartbeat->runNow($task));

        return;
    }

    foreach ($heartbeat->beat((int) $this->option('budget')) as $name => $outcome) {
        $this->line(str_pad($name, 32).$outcome);
    }
})->purpose('Run due maintenance tasks now (normally triggered by site traffic)');

Artisan::command('statementra:create-admin {email} {--name= : Display name} {--role=super_admin : super_admin, operations_admin, content_admin, finance_admin or ai_admin}', function (string $email) {
    $role = AdminRole::tryFrom((string) $this->option('role'));
    if (! $role || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Give a valid email address and one of: '.implode(', ', array_column(AdminRole::cases(), 'value')));

        return 1;
    }

    $password = Laravel\Prompts\password(
        label: 'Password (at least 12 characters)',
        validate: fn (string $value) => mb_strlen($value) < 12 ? 'Use at least 12 characters.' : null,
    );

    (new RolesAndPermissionsSeeder)->run();

    $admin = AdminUser::query()->updateOrCreate(
        ['email' => mb_strtolower($email)],
        ['name' => $this->option('name') ?: Str::before($email, '@'), 'password' => $password, 'is_active' => true],
    );
    $admin->syncRoles([$role->value]);

    Audit::log('admin.created_from_console', $admin, meta: ['role' => $role->value]);
    $this->info("Administrator {$admin->email} ({$role->value}) is ready. They will set up two-factor authentication at first sign-in.");
})->purpose('Create (or reset the password of) an admin panel account');
