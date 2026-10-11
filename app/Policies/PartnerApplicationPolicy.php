<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\PartnerApplication;

/**
 * Partner applications: referrals.view to read them, referrals.manage to
 * approve, reject, keep notes and delete (erasure requests). Applications are
 * created by applicants only.
 */
class PartnerApplicationPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $this->can($admin, Permission::ReferralsView) || $this->can($admin, Permission::ReferralsManage);
    }

    public function view(AdminUser $admin, PartnerApplication $application): bool
    {
        return $this->viewAny($admin);
    }

    /** Approve or reject: only an application waiting for review. */
    public function decide(AdminUser $admin, PartnerApplication $application): bool
    {
        return $this->can($admin, Permission::ReferralsManage) && $application->status === PartnerApplication::STATUS_SUBMITTED;
    }

    public function update(AdminUser $admin, PartnerApplication $application): bool
    {
        return $this->can($admin, Permission::ReferralsManage);
    }

    public function delete(AdminUser $admin, PartnerApplication $application): bool
    {
        return $this->can($admin, Permission::ReferralsManage);
    }

    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }

    private function can(AdminUser $admin, string $permission): bool
    {
        return $admin->is_active && $admin->checkPermissionTo($permission, 'admin');
    }
}
