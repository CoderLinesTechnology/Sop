<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\Feedback;

/** Customer ratings are read-only in the panel (feedback.view); they can be exported. */
class FeedbackPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $admin->checkPermissionTo(Permission::FeedbackView, 'admin');
    }

    public function view(AdminUser $admin, Feedback $feedback): bool
    {
        return $admin->checkPermissionTo(Permission::FeedbackView, 'admin');
    }

    public function export(AdminUser $admin): bool
    {
        return $admin->checkPermissionTo(Permission::FeedbackView, 'admin');
    }

    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function update(AdminUser $admin, Feedback $feedback): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, Feedback $feedback): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }
}
