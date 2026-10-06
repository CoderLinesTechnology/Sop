<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\Coupon;

/**
 * Coupons are managed by finance (coupons.manage). They are archived (soft-deleted) rather than destroyed so order history and redemptions stay intact.
 */
class CouponPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $this->manages($admin);
    }

    public function view(AdminUser $admin, Coupon $coupon): bool
    {
        return $this->manages($admin);
    }

    public function create(AdminUser $admin): bool
    {
        return $this->manages($admin);
    }

    public function update(AdminUser $admin, Coupon $coupon): bool
    {
        return $this->manages($admin);
    }

    public function delete(AdminUser $admin, Coupon $coupon): bool
    {
        return $this->manages($admin);
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return $this->manages($admin);
    }

    public function restore(AdminUser $admin, Coupon $coupon): bool
    {
        return $this->manages($admin);
    }

    public function restoreAny(AdminUser $admin): bool
    {
        return $this->manages($admin);
    }

    public function forceDelete(AdminUser $admin, Coupon $coupon): bool
    {
        return false;
    }

    public function forceDeleteAny(AdminUser $admin): bool
    {
        return false;
    }

    public function replicate(AdminUser $admin, Coupon $coupon): bool
    {
        return $this->manages($admin);
    }

    private function manages(AdminUser $admin): bool
    {
        return $admin->checkPermissionTo(Permission::CouponsManage, 'admin');
    }
}
