<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Models\AdminUser;
use App\Models\PromptVersion;

/**
 * Prompt versions. Drafts are editable by AI administrators; active and
 * archived versions are immutable. Only prompts.activate may put a version
 * into production, and nothing activates a prompt automatically.
 */
class PromptVersionPolicy
{
    public function viewAny(AdminUser $user): bool
    {
        return AdminAccess::allowsAny([Permission::AiManage, Permission::PromptsActivate], $user);
    }

    public function view(AdminUser $user, PromptVersion $version): bool
    {
        return $this->viewAny($user);
    }

    public function create(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function update(AdminUser $user, PromptVersion $version): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user)
            && $version->getOriginal('status', $version->status) === PromptVersion::STATUS_DRAFT;
    }

    public function delete(AdminUser $user, PromptVersion $version): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user)
            && $version->getOriginal('status', $version->status) === PromptVersion::STATUS_DRAFT;
    }

    public function deleteAny(AdminUser $user): bool
    {
        return false;
    }

    /** Put a draft (or a previously archived version, as a rollback) into production. */
    public function activate(AdminUser $user, PromptVersion $version): bool
    {
        return AdminAccess::allows(Permission::PromptsActivate, $user)
            && $version->status !== PromptVersion::STATUS_ACTIVE;
    }
}
