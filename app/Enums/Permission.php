<?php

namespace App\Enums;

/**
 * Admin permission names (spatie/laravel-permission, guard "admin").
 */
final class Permission
{
    public const DashboardView = 'dashboard.view';
    public const AnalyticsView = 'analytics.view';
    public const OrdersView = 'orders.view';
    public const OrdersManage = 'orders.manage';
    public const OrdersOverride = 'orders.override';
    public const CustomerDataView = 'customers.view';
    public const DocumentsManage = 'documents.manage';
    public const EmailsManage = 'emails.manage';
    public const RevisionsManage = 'revisions.manage';
    public const PaymentsView = 'payments.view';
    public const RefundsRequest = 'refunds.request';
    public const RefundsApprove = 'refunds.approve';
    public const ServicesManage = 'services.manage';
    public const ServicesContent = 'services.content';
    public const PricingManage = 'pricing.manage';
    public const CouponsManage = 'coupons.manage';
    public const PromotionsManage = 'promotions.manage';
    public const ContentManage = 'content.manage';
    public const EmailTemplatesManage = 'email_templates.manage';
    public const NewsletterManage = 'newsletter.manage';
    public const SupportManage = 'support.manage';
    public const FeedbackView = 'feedback.view';
    public const AiManage = 'ai.manage';
    public const PromptsActivate = 'prompts.activate';
    public const RequirementsManage = 'requirements.manage';
    public const TemplatesManage = 'templates.manage';
    public const SettingsManage = 'settings.manage';
    public const AdminsManage = 'admins.manage';
    public const AuditView = 'audit.view';

    /** @return list<string> */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
