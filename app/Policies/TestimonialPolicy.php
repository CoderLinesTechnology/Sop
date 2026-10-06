<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Models\AdminUser;
use App\Models\Testimonial;

/** Website content is managed with the content.manage permission. */
class TestimonialPolicy
{
    public function viewAny(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::ContentManage, $user);
    }

    public function view(AdminUser $user, Testimonial $record): bool
    {
        return AdminAccess::allows(Permission::ContentManage, $user);
    }

    public function create(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::ContentManage, $user);
    }

    public function update(AdminUser $user, Testimonial $record): bool
    {
        return AdminAccess::allows(Permission::ContentManage, $user);
    }

    public function reorder(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::ContentManage, $user);
    }

    public function delete(AdminUser $user, Testimonial $record): bool
    {
        return AdminAccess::allows(Permission::ContentManage, $user);
    }

    public function deleteAny(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::ContentManage, $user);
    }
}
