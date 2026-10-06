<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Models\AdminUser;
use App\Models\AiModelPrice;

/** Model price table used for AI cost estimates (ai.manage). */
class AiModelPricePolicy
{
    public function viewAny(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function view(AdminUser $user, AiModelPrice $price): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function create(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function update(AdminUser $user, AiModelPrice $price): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function delete(AdminUser $user, AiModelPrice $price): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function deleteAny(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }
}
