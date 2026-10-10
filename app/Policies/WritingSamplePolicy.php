<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Models\AdminUser;
use App\Models\WritingSample;

/** Writing samples the AI studies (ai.manage). */
class WritingSamplePolicy
{
    public function viewAny(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function view(AdminUser $user, WritingSample $sample): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function create(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function update(AdminUser $user, WritingSample $sample): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function delete(AdminUser $user, WritingSample $sample): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function deleteAny(AdminUser $user): bool
    {
        return false;
    }
}
