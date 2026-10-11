<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\ReferralPayout;

/**
 * Payout requests: referrals.view to see them, referrals.payouts to see the
 * payment details and to mark them paid or reject them. They are created by
 * referrers only and never edited by hand or deleted.
 */
class ReferralPayoutPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $this->can($admin, Permission::ReferralsView) || $this->can($admin, Permission::ReferralsManage) || $this->can($admin, Permission::ReferralsPayouts);
    }

    public function view(AdminUser $admin, ReferralPayout $payout): bool
    {
        return $this->viewAny($admin);
    }

    /** Decide (mark paid / reject) a request that is still open. */
    public function decide(AdminUser $admin, ReferralPayout $payout): bool
    {
        return $this->can($admin, Permission::ReferralsPayouts) && $payout->isOpen();
    }

    /** Payment details in full (audited when revealed). */
    public function viewPaymentDetails(AdminUser $admin, ?ReferralPayout $payout = null): bool
    {
        return $this->can($admin, Permission::ReferralsPayouts);
    }

    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function update(AdminUser $admin, ReferralPayout $payout): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, ReferralPayout $payout): bool
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
