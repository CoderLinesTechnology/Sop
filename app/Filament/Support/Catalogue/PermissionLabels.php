<?php

namespace App\Filament\Support\Catalogue;

use App\Enums\AdminRole;
use App\Enums\Permission;
use Illuminate\Support\Str;

/** Human-readable names for admin permissions and roles. */
final class PermissionLabels
{
    private const LABELS = [
        Permission::DashboardView => 'View the dashboard',
        Permission::AnalyticsView => 'View analytics',
        Permission::OrdersView => 'View orders',
        Permission::OrdersManage => 'Manage orders (notes, resends, information requests)',
        Permission::OrdersOverride => 'Override order status and the AI pipeline',
        Permission::CustomerDataView => 'View customer personal data',
        Permission::DocumentsManage => 'Manage generated documents',
        Permission::EmailsManage => 'View and resend customer emails',
        Permission::RevisionsManage => 'Manage revisions',
        Permission::PaymentsView => 'View payments',
        Permission::RefundsRequest => 'Request refunds',
        Permission::RefundsApprove => 'Approve refunds',
        Permission::ServicesManage => 'Create, archive and delete services',
        Permission::ServicesContent => 'Edit service content and order forms',
        Permission::PricingManage => 'Change prices',
        Permission::CouponsManage => 'Manage coupons',
        Permission::PromotionsManage => 'Manage promotions',
        Permission::ContentManage => 'Manage website content (pages, articles, FAQs, testimonials)',
        Permission::EmailTemplatesManage => 'Edit email templates',
        Permission::NewsletterManage => 'Manage newsletter subscribers',
        Permission::SupportManage => 'Handle support messages',
        Permission::FeedbackView => 'View customer feedback',
        Permission::AiManage => 'Configure AI workflows, prompts and model prices; AI control centre',
        Permission::PromptsActivate => 'Activate prompt versions (put prompts into production)',
        Permission::RequirementsManage => 'Manage requirement rules',
        Permission::TemplatesManage => 'Manage document templates',
        Permission::SettingsManage => 'Change site settings',
        Permission::AdminsManage => 'Manage administrators and role permissions',
        Permission::AuditView => 'View the audit log',
    ];

    private const ROLE_DESCRIPTIONS = [
        'super_admin' => 'Everything, including administrators, settings and the audit log.',
        'operations_admin' => 'Orders, customers, documents, emails, revisions and support.',
        'content_admin' => 'Website content, service copy and order forms, email templates.',
        'finance_admin' => 'Payments, refunds, prices, coupons, promotions and analytics.',
        'ai_admin' => 'AI workflows and prompts, requirement rules, document templates.',
    ];

    public static function permission(string $name): string
    {
        return self::LABELS[$name] ?? Str::of($name)->replace(['.', '_'], ' ')->ucfirst()->toString();
    }

    /** @return array<string, string> every known permission => label, in a stable order */
    public static function permissionOptions(array $extra = []): array
    {
        $options = [];
        foreach (array_unique([...Permission::all(), ...$extra]) as $name) {
            $options[$name] = self::permission($name);
        }

        return $options;
    }

    public static function role(string $name): string
    {
        return AdminRole::tryFrom($name)?->getLabel() ?? Str::of($name)->replace('_', ' ')->title()->toString();
    }

    public static function roleDescription(string $name): ?string
    {
        return self::ROLE_DESCRIPTIONS[$name] ?? null;
    }

    /** @return array<string, string> */
    public static function roleOptions(): array
    {
        $options = [];
        foreach (AdminRole::cases() as $role) {
            $options[$role->value] = $role->getLabel();
        }

        return $options;
    }
}
