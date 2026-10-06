<?php

namespace App\Listeners;

use App\Models\AdminUser;
use App\Support\Audit;
use App\Support\SecurityLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Request;

/** Audits administrator sign-ins and flags failed or throttled attempts. */
class RecordAuthenticationEvents
{
    public function handleLogin(Login $event): void
    {
        if ($event->guard !== 'admin' || ! $event->user instanceof AdminUser) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now(), 'last_login_ip' => Request::ip()])->saveQuietly();
        Audit::log('admin.login', $event->user, admin: $event->user);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->guard === 'admin' && $event->user instanceof AdminUser) {
            Audit::log('admin.logout', $event->user, admin: $event->user);
        }
    }

    public function handleFailed(Failed $event): void
    {
        if ($event->guard !== 'admin') {
            return;
        }

        SecurityLog::record('admin_login_failed', 'low', ['email' => mb_substr((string) ($event->credentials['email'] ?? ''), 0, 120)]);
    }

    public function handleLockout(Lockout $event): void
    {
        SecurityLog::record('login_lockout', 'high', ['path' => $event->request->path()]);
    }
}
