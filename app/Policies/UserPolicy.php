<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\User;

/**
 * Optional customer accounts (customers.view). Read-only: accounts are
 * created by customers through magic-link sign-in.
 */
class UserPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $admin->checkPermissionTo(Permission::CustomerDataView, 'admin');
    }

    public function view(AdminUser $admin, User $user): bool
    {
        return $admin->checkPermissionTo(Permission::CustomerDataView, 'admin');
    }

    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function update(AdminUser $admin, User $user): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, User $user): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }
}
