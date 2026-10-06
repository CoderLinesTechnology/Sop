<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Models\AdminUser;

/**
 * Administrator accounts (admins.manage). Accounts are deactivated rather
 * than deleted so the audit trail keeps pointing at real people.
 */
class AdminUserPolicy
{
    public function viewAny(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::AdminsManage, $user);
    }

    public function view(AdminUser $user, AdminUser $admin): bool
    {
        return AdminAccess::allows(Permission::AdminsManage, $user);
    }

    public function create(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::AdminsManage, $user);
    }

    public function update(AdminUser $user, AdminUser $admin): bool
    {
        return AdminAccess::allows(Permission::AdminsManage, $user);
    }

    /** Clear another administrator's authenticator so they must enrol again. */
    public function resetMfa(AdminUser $user, AdminUser $admin): bool
    {
        return AdminAccess::allows(Permission::AdminsManage, $user)
            && $user->isNot($admin)
            && $admin->hasMfaEnabled();
    }

    public function delete(AdminUser $user, AdminUser $admin): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $user): bool
    {
        return false;
    }
}
