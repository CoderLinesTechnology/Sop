<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\Referrer;

/**
 * Referrers: referrals.view to see them (with their referred orders and
 * commission), referrals.manage to create, edit, deactivate, manage the
 * dashboard link, erase and record payouts. Referrers are never deleted: the
 * commission ledger and order attribution point at them (erasure anonymises).
 */
class ReferrerPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $this->views($admin);
    }

    public function view(AdminUser $admin, Referrer $referrer): bool
    {
        return $this->views($admin);
    }

    public function create(AdminUser $admin): bool
    {
        return $this->manages($admin);
    }

    public function update(AdminUser $admin, Referrer $referrer): bool
    {
        return $this->manages($admin);
    }

    /** Lifecycle actions: dashboard link, activation, erasure and payouts. */
    public function manage(AdminUser $admin, Referrer $referrer): bool
    {
        return $this->manages($admin);
    }

    /** Full payment details (audited when revealed). */
    public function viewPaymentDetails(AdminUser $admin, Referrer $referrer): bool
    {
        return $admin->is_active && $admin->checkPermissionTo(Permission::ReferralsPayouts, 'admin');
    }

    public function delete(AdminUser $admin, Referrer $referrer): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }

    public function restore(AdminUser $admin, Referrer $referrer): bool
    {
        return false;
    }

    public function restoreAny(AdminUser $admin): bool
    {
        return false;
    }

    public function forceDelete(AdminUser $admin, Referrer $referrer): bool
    {
        return false;
    }

    public function forceDeleteAny(AdminUser $admin): bool
    {
        return false;
    }

    public function replicate(AdminUser $admin, Referrer $referrer): bool
    {
        return false;
    }

    private function views(AdminUser $admin): bool
    {
        return $admin->is_active
            && ($admin->checkPermissionTo(Permission::ReferralsView, 'admin') || $admin->checkPermissionTo(Permission::ReferralsManage, 'admin'));
    }

    private function manages(AdminUser $admin): bool
    {
        return $admin->is_active && $admin->checkPermissionTo(Permission::ReferralsManage, 'admin');
    }
}
