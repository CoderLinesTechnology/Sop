<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Enums\Permission;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Models\AdminUser;
use Spatie\Permission\Models\Role;

/**
 * Admin roles (spatie Role, guard "admin"). Applied explicitly by the Roles
 * resource because Laravel cannot auto-discover a policy for a vendor model.
 * Roles mirror App\Enums\AdminRole, so they are never created or deleted
 * here, and the Super Admin role always keeps every permission.
 */
class RolePolicy
{
    public function viewAny(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::AdminsManage, $user);
    }

    public function view(AdminUser $user, Role $role): bool
    {
        return AdminAccess::allows(Permission::AdminsManage, $user);
    }

    public function create(AdminUser $user): bool
    {
        return false;
    }

    public function update(AdminUser $user, Role $role): bool
    {
        return AdminAccess::allows(Permission::AdminsManage, $user)
            && $role->name !== AdminRole::SuperAdmin->value;
    }

    public function delete(AdminUser $user, Role $role): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $user): bool
    {
        return false;
    }
}
