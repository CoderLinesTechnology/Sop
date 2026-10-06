<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\Promotion;

/**
 * Promotions are managed by finance (promotions.manage). They are archived (soft-deleted) rather than destroyed so historical orders keep their promotion.
 */
class PromotionPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $this->manages($admin);
    }

    public function view(AdminUser $admin, Promotion $promotion): bool
    {
        return $this->manages($admin);
    }

    public function create(AdminUser $admin): bool
    {
        return $this->manages($admin);
    }

    public function update(AdminUser $admin, Promotion $promotion): bool
    {
        return $this->manages($admin);
    }

    public function delete(AdminUser $admin, Promotion $promotion): bool
    {
        return $this->manages($admin);
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return $this->manages($admin);
    }

    public function restore(AdminUser $admin, Promotion $promotion): bool
    {
        return $this->manages($admin);
    }

    public function restoreAny(AdminUser $admin): bool
    {
        return $this->manages($admin);
    }

    public function forceDelete(AdminUser $admin, Promotion $promotion): bool
    {
        return false;
    }

    public function forceDeleteAny(AdminUser $admin): bool
    {
        return false;
    }

    public function replicate(AdminUser $admin, Promotion $promotion): bool
    {
        return $this->manages($admin);
    }

    private function manages(AdminUser $admin): bool
    {
        return $admin->checkPermissionTo(Permission::PromotionsManage, 'admin');
    }
}
