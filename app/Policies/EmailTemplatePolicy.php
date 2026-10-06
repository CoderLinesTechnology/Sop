<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Models\AdminUser;
use App\Models\EmailTemplate;

/** Transactional email templates are a fixed set: editable, never created or deleted in the panel. */
class EmailTemplatePolicy
{
    public function viewAny(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::EmailTemplatesManage, $user);
    }

    public function view(AdminUser $user, EmailTemplate $template): bool
    {
        return AdminAccess::allows(Permission::EmailTemplatesManage, $user);
    }

    public function create(AdminUser $user): bool
    {
        return false;
    }

    public function update(AdminUser $user, EmailTemplate $template): bool
    {
        return AdminAccess::allows(Permission::EmailTemplatesManage, $user);
    }

    public function delete(AdminUser $user, EmailTemplate $template): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $user): bool
    {
        return false;
    }
}
