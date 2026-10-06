<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\ContactMessage;

/**
 * The support inbox (support.manage). Messages come from the contact form;
 * administrators only change their handling status.
 */
class ContactMessagePolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $admin->checkPermissionTo(Permission::SupportManage, 'admin');
    }

    public function view(AdminUser $admin, ContactMessage $contactMessage): bool
    {
        return $admin->checkPermissionTo(Permission::SupportManage, 'admin');
    }

    /** Mark as open / handled. */
    public function handle(AdminUser $admin, ContactMessage $contactMessage): bool
    {
        return $admin->checkPermissionTo(Permission::SupportManage, 'admin');
    }

    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function update(AdminUser $admin, ContactMessage $contactMessage): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, ContactMessage $contactMessage): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }
}
