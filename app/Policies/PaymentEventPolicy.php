<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\PaymentEvent;

/** Raw payment-provider webhook deliveries; read-only forensics. */
class PaymentEventPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $admin->checkPermissionTo(Permission::PaymentsView, 'admin');
    }

    public function view(AdminUser $admin, PaymentEvent $paymentEvent): bool
    {
        return $admin->checkPermissionTo(Permission::PaymentsView, 'admin');
    }

    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function update(AdminUser $admin, PaymentEvent $paymentEvent): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, PaymentEvent $paymentEvent): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }
}
