<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\Refund;

/**
 * Refunds are requested from the order page (refunds.request) and approved,
 * rejected or marked processed by finance (refunds.approve). They are never
 * edited or deleted through the panel.
 */
class RefundPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $this->canSeeRefunds($admin);
    }

    public function view(AdminUser $admin, Refund $refund): bool
    {
        return $this->canSeeRefunds($admin);
    }

    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function update(AdminUser $admin, Refund $refund): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, Refund $refund): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }

    public function approve(AdminUser $admin, Refund $refund): bool
    {
        return $admin->checkPermissionTo(Permission::RefundsApprove, 'admin');
    }

    private function canSeeRefunds(AdminUser $admin): bool
    {
        return $admin->checkPermissionTo(Permission::RefundsApprove, 'admin')
            || $admin->checkPermissionTo(Permission::RefundsRequest, 'admin')
            || $admin->checkPermissionTo(Permission::PaymentsView, 'admin');
    }
}
