<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Filament\Support\Catalogue\PageSections;
use App\Models\AdminUser;
use App\Models\Page;

/** CMS pages (content.manage). System pages can be edited but never deleted. */
class PagePolicy
{
    public function viewAny(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::ContentManage, $user);
    }

    public function view(AdminUser $user, Page $page): bool
    {
        return AdminAccess::allows(Permission::ContentManage, $user);
    }

    public function create(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::ContentManage, $user);
    }

    public function update(AdminUser $user, Page $page): bool
    {
        return AdminAccess::allows(Permission::ContentManage, $user);
    }

    public function delete(AdminUser $user, Page $page): bool
    {
        return AdminAccess::allows(Permission::ContentManage, $user)
            && ! PageSections::isSystemPage($page->getOriginal('slug') ?? $page->slug);
    }

    public function deleteAny(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::ContentManage, $user);
    }
}
