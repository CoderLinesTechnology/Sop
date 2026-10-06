<?php

namespace App\Policies;

use App\Filament\Support\Catalogue\ServiceAccess;
use App\Models\AdminUser;
use App\Models\Service;

/**
 * Services: content editors (services.content) and finance (pricing.manage)
 * can open and edit a service, but each only changes the fields they are
 * responsible for (enforced field by field in the service form).
 */
class ServicePolicy
{
    public function viewAny(AdminUser $user): bool
    {
        return ServiceAccess::canView($user);
    }

    public function view(AdminUser $user, Service $service): bool
    {
        return ServiceAccess::canView($user);
    }

    public function create(AdminUser $user): bool
    {
        return ServiceAccess::canCreate($user);
    }

    public function replicate(AdminUser $user, Service $service): bool
    {
        return ServiceAccess::canCreate($user);
    }

    public function update(AdminUser $user, Service $service): bool
    {
        return ServiceAccess::canEditContent($user) || ServiceAccess::canEditPricing($user);
    }

    public function reorder(AdminUser $user): bool
    {
        return ServiceAccess::canEditContent($user);
    }

    /** Archive (soft delete). */
    public function delete(AdminUser $user, Service $service): bool
    {
        return ServiceAccess::canManage($user) && ! $service->trashed();
    }

    public function deleteAny(AdminUser $user): bool
    {
        return ServiceAccess::canManage($user);
    }

    public function restore(AdminUser $user, Service $service): bool
    {
        return ServiceAccess::canManage($user) && $service->trashed();
    }

    public function restoreAny(AdminUser $user): bool
    {
        return ServiceAccess::canManage($user);
    }

    /** Permanent deletion is only possible for archived services that never had an order. */
    public function forceDelete(AdminUser $user, Service $service): bool
    {
        if (! ServiceAccess::canManage($user) || ! $service->trashed()) {
            return false;
        }

        // Tables preload orders_count; fall back to a query elsewhere.
        $orders = $service->getAttribute('orders_count');

        return $orders !== null ? (int) $orders === 0 : ! $service->orders()->exists();
    }

    public function forceDeleteAny(AdminUser $user): bool
    {
        return false;
    }
}
