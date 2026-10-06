<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Models\AdminUser;
use App\Models\AuditLog;

/** The audit log is append-only and read-only in the panel (audit.view). */
class AuditLogPolicy
{
    public function viewAny(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::AuditView, $user);
    }

    public function view(AdminUser $user, AuditLog $log): bool
    {
        return AdminAccess::allows(Permission::AuditView, $user);
    }

    public function create(AdminUser $user): bool
    {
        return false;
    }

    public function update(AdminUser $user, AuditLog $log): bool
    {
        return false;
    }

    public function delete(AdminUser $user, AuditLog $log): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $user): bool
    {
        return false;
    }
}
