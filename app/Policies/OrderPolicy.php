<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\Order;

/**
 * Orders are created by customers only and change through domain services,
 * so the panel never creates, edits or deletes them directly. The custom
 * abilities below gate the operational actions on the order page.
 */
class OrderPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $admin->checkPermissionTo(Permission::OrdersView, 'admin');
    }

    public function view(AdminUser $admin, Order $order): bool
    {
        return $admin->checkPermissionTo(Permission::OrdersView, 'admin');
    }

    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function update(AdminUser $admin, Order $order): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, Order $order): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }

    public function restore(AdminUser $admin, Order $order): bool
    {
        return false;
    }

    public function forceDelete(AdminUser $admin, Order $order): bool
    {
        return false;
    }

    public function replicate(AdminUser $admin, Order $order): bool
    {
        return false;
    }

    /** Pipeline controls, status changes, information requests, link rotation. */
    public function manage(AdminUser $admin, Order $order): bool
    {
        return $admin->checkPermissionTo(Permission::OrdersManage, 'admin');
    }

    /** Force any status transition (with a mandatory reason). */
    public function override(AdminUser $admin, Order $order): bool
    {
        return $admin->checkPermissionTo(Permission::OrdersOverride, 'admin');
    }

    /** Answers, uploaded files and the applicant profile. */
    public function viewCustomerData(AdminUser $admin, Order $order): bool
    {
        return $admin->checkPermissionTo(Permission::CustomerDataView, 'admin');
    }

    /** Open or download the generated PDF / DOCX files. */
    public function viewDocuments(AdminUser $admin, Order $order): bool
    {
        return $admin->checkPermissionTo(Permission::CustomerDataView, 'admin')
            || $admin->checkPermissionTo(Permission::DocumentsManage, 'admin');
    }

    /** Upload, replace, re-render, edit and deliver document versions. */
    public function manageDocuments(AdminUser $admin, Order $order): bool
    {
        return $admin->checkPermissionTo(Permission::DocumentsManage, 'admin');
    }

    /** Resend customer emails. */
    public function manageEmails(AdminUser $admin, Order $order): bool
    {
        return $admin->checkPermissionTo(Permission::EmailsManage, 'admin');
    }

    /** Read the rendered content of emails sent to the customer. */
    public function viewEmails(AdminUser $admin, Order $order): bool
    {
        return $admin->checkPermissionTo(Permission::EmailsManage, 'admin')
            || $admin->checkPermissionTo(Permission::CustomerDataView, 'admin');
    }

    public function refund(AdminUser $admin, Order $order): bool
    {
        return $admin->checkPermissionTo(Permission::RefundsRequest, 'admin');
    }

    /** Internal notes are visible to, and can be added by, anyone who can see the order. */
    public function addNote(AdminUser $admin, Order $order): bool
    {
        return $admin->checkPermissionTo(Permission::OrdersView, 'admin');
    }
}
