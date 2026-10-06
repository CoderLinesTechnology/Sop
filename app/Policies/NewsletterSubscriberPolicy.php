<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\NewsletterSubscriber;

/** Newsletter subscribers (newsletter.manage): list and audited CSV export only. */
class NewsletterSubscriberPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $admin->checkPermissionTo(Permission::NewsletterManage, 'admin');
    }

    public function view(AdminUser $admin, NewsletterSubscriber $newsletterSubscriber): bool
    {
        return $admin->checkPermissionTo(Permission::NewsletterManage, 'admin');
    }

    public function export(AdminUser $admin): bool
    {
        return $admin->checkPermissionTo(Permission::NewsletterManage, 'admin');
    }

    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function update(AdminUser $admin, NewsletterSubscriber $newsletterSubscriber): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, NewsletterSubscriber $newsletterSubscriber): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }
}
