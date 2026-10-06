<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\SecurityEvent;

/** Suspicious-activity log written by App\Support\SecurityLog; read-only. */
class SecurityEventPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $admin->checkPermissionTo(Permission::AuditView, 'admin');
    }

    public function view(AdminUser $admin, SecurityEvent $securityEvent): bool
    {
        return $admin->checkPermissionTo(Permission::AuditView, 'admin');
    }

    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function update(AdminUser $admin, SecurityEvent $securityEvent): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, SecurityEvent $securityEvent): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }
}
