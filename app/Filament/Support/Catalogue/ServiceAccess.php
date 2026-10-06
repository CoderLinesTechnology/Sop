<?php

namespace App\Filament\Support\Catalogue;

use App\Enums\Permission;
use App\Models\AdminUser;

/**
 * Who may do what with services.
 *
 *  - services.manage   create (with pricing.manage), archive, restore, delete, duplicate
 *  - services.content  edit everything except prices (copy, form builder, AI guidance, SEO, FAQs)
 *  - pricing.manage    edit prices (price, original price, currency, promo label, revision fee)
 */
final class ServiceAccess
{
    /** Attributes that only administrators with pricing.manage may change. */
    public const PRICING_ATTRIBUTES = ['price', 'compare_at_price', 'currency', 'promo_label', 'revision_fee'];

    public static function canView(?AdminUser $user = null): bool
    {
        return AdminAccess::allowsAny([Permission::ServicesManage, Permission::ServicesContent, Permission::PricingManage], $user);
    }

    public static function canEditContent(?AdminUser $user = null): bool
    {
        return AdminAccess::allowsAny([Permission::ServicesManage, Permission::ServicesContent], $user);
    }

    public static function canEditPricing(?AdminUser $user = null): bool
    {
        return AdminAccess::allows(Permission::PricingManage, $user);
    }

    public static function canManage(?AdminUser $user = null): bool
    {
        return AdminAccess::allows(Permission::ServicesManage, $user);
    }

    /** A new service needs a price, so creating one requires both permissions. */
    public static function canCreate(?AdminUser $user = null): bool
    {
        return self::canManage($user) && self::canEditPricing($user);
    }
}
