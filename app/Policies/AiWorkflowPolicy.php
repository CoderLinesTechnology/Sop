<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Models\AdminUser;
use App\Models\AiJob;
use App\Models\AiWorkflow;

/** AI workflows (ai.manage). A workflow that has produced documents is deactivated, never deleted. */
class AiWorkflowPolicy
{
    public function viewAny(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function view(AdminUser $user, AiWorkflow $workflow): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function create(AdminUser $user): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function replicate(AdminUser $user, AiWorkflow $workflow): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function update(AdminUser $user, AiWorkflow $workflow): bool
    {
        return AdminAccess::allows(Permission::AiManage, $user);
    }

    public function delete(AdminUser $user, AiWorkflow $workflow): bool
    {
        if (! AdminAccess::allows(Permission::AiManage, $user) || $workflow->is_default) {
            return false;
        }

        // Tables preload these counts; fall back to queries elsewhere.
        $services = $workflow->getAttribute('all_services_count');
        $jobs = $workflow->getAttribute('jobs_count');

        return ($services !== null ? (int) $services === 0 : ! $workflow->services()->withTrashed()->exists())
            && ($jobs !== null ? (int) $jobs === 0 : ! AiJob::query()->where('ai_workflow_id', $workflow->id)->exists());
    }

    public function deleteAny(AdminUser $user): bool
    {
        return false;
    }
}
