<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\Payment;

/** Payments are recorded by PaymentConfirmationService only; the panel is read-only. */
class PaymentPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $admin->checkPermissionTo(Permission::PaymentsView, 'admin');
    }

    public function view(AdminUser $admin, Payment $payment): bool
    {
        return $admin->checkPermissionTo(Permission::PaymentsView, 'admin');
    }

    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function update(AdminUser $admin, Payment $payment): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, Payment $payment): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }
}
