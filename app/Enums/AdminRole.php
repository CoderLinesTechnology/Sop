<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Administrator roles. Each role grants only the permissions it needs; the
 * mapping is the single source of truth used by the RolesAndPermissionsSeeder.
 */
enum AdminRole: string implements HasLabel
{
    case SuperAdmin = 'super_admin';
    case Operations = 'operations_admin';
    case Content = 'content_admin';
    case Finance = 'finance_admin';
    case Ai = 'ai_admin';

    public function getLabel(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Operations => 'Operations Admin',
            self::Content => 'Content Admin',
            self::Finance => 'Finance Admin',
            self::Ai => 'AI Admin',
        };
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => Permission::all(),
            self::Operations => [
                Permission::DashboardView, Permission::OrdersView, Permission::OrdersManage,
                Permission::OrdersOverride, Permission::CustomerDataView, Permission::DocumentsManage,
                Permission::EmailsManage, Permission::RevisionsManage, Permission::SupportManage,
                Permission::FeedbackView, Permission::RefundsRequest,
            ],
            self::Content => [
                Permission::DashboardView, Permission::ServicesContent, Permission::ContentManage,
                Permission::EmailTemplatesManage, Permission::NewsletterManage, Permission::FeedbackView,
            ],
            self::Finance => [
                Permission::DashboardView, Permission::OrdersView, Permission::PaymentsView,
                Permission::RefundsRequest, Permission::RefundsApprove, Permission::PricingManage,
                Permission::CouponsManage, Permission::PromotionsManage, Permission::AnalyticsView,
            ],
            self::Ai => [
                Permission::DashboardView, Permission::OrdersView, Permission::AiManage,
                Permission::PromptsActivate, Permission::RequirementsManage, Permission::TemplatesManage,
                Permission::DocumentsManage, Permission::FeedbackView, Permission::AnalyticsView,
            ],
        };
    }
}
