<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Models\AdminUser;
use App\Models\RequirementRule;

/** Country / platform / institution requirement rules (requirements.manage). */
class RequirementRulePolicy
{
    public function viewAny(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::RequirementsManage, $user);
    }

    public function view(AdminUser $user, RequirementRule $rule): bool
    {
        return AdminAccess::allows(Permission::RequirementsManage, $user);
    }

    public function create(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::RequirementsManage, $user);
    }

    public function replicate(AdminUser $user, RequirementRule $rule): bool
    {
        return AdminAccess::allows(Permission::RequirementsManage, $user);
    }

    public function update(AdminUser $user, RequirementRule $rule): bool
    {
        return AdminAccess::allows(Permission::RequirementsManage, $user);
    }

    public function delete(AdminUser $user, RequirementRule $rule): bool
    {
        return AdminAccess::allows(Permission::RequirementsManage, $user);
    }

    public function deleteAny(AdminUser $user): bool
    {
        return false;
    }
}
