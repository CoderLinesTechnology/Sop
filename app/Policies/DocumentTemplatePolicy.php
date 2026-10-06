<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Models\AdminUser;
use App\Models\DocumentTemplate;

/** Document formatting templates (templates.manage). The default template cannot be deleted. */
class DocumentTemplatePolicy
{
    public function viewAny(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::TemplatesManage, $user);
    }

    public function view(AdminUser $user, DocumentTemplate $template): bool
    {
        return AdminAccess::allows(Permission::TemplatesManage, $user);
    }

    public function create(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::TemplatesManage, $user);
    }

    public function replicate(AdminUser $user, DocumentTemplate $template): bool
    {
        return AdminAccess::allows(Permission::TemplatesManage, $user);
    }

    public function update(AdminUser $user, DocumentTemplate $template): bool
    {
        return AdminAccess::allows(Permission::TemplatesManage, $user);
    }

    public function delete(AdminUser $user, DocumentTemplate $template): bool
    {
        return AdminAccess::allows(Permission::TemplatesManage, $user) && ! $template->is_default;
    }

    public function deleteAny(AdminUser $user): bool
    {
        return false;
    }
}
