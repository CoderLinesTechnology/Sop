<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\ReferralCommission;

/**
 * Commission ledger entries are written only by App\Domain\Referrals. In the
 * admin they can be viewed (referrals.view) and stamped as paid out
 * (referrals.manage); never created, edited or deleted by hand.
 */
class ReferralCommissionPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $admin->is_active
            && ($admin->checkPermissionTo(Permission::ReferralsView, 'admin') || $admin->checkPermissionTo(Permission::ReferralsManage, 'admin'));
    }

    public function view(AdminUser $admin, ReferralCommission $commission): bool
    {
        return $this->viewAny($admin);
    }

    /** Recording a payout (paid_at, payout reference) is the only change allowed. */
    public function update(AdminUser $admin, ReferralCommission $commission): bool
    {
        return $admin->is_active && $admin->checkPermissionTo(Permission::ReferralsManage, 'admin') && $commission->paid_at === null;
    }

    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, ReferralCommission $commission): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }
}
