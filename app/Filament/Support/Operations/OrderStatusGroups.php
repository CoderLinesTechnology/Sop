<?php

namespace App\Filament\Support\Operations;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;

/**
 * Named groups of order statuses used by the order list, the navigation badge
 * and the dashboard, so every screen agrees on what "needs attention" means.
 */
final class OrderStatusGroups
{
    /** Orders an administrator should look at. */
    public const ATTENTION = [
        OrderStatus::ManualReview,
        OrderStatus::ProcessingFailed,
        OrderStatus::DeliveryFailed,
        OrderStatus::NeedsInformation,
    ];

    /** Failures that need an administrator to act (excludes waiting for the customer). */
    public const FAILED = [
        OrderStatus::ProcessingFailed,
        OrderStatus::DeliveryFailed,
        OrderStatus::ManualReview,
    ];

    /** Unpaid drafts hidden from the order list by default (see Order::scopeSubmitted). */
    public const DRAFTS = [
        OrderStatus::New,
        OrderStatus::FormSubmitted,
    ];

    /** @param  list<OrderStatus>  $statuses
     *  @return list<string> */
    public static function values(array $statuses): array
    {
        return array_map(fn (OrderStatus $status) => $status->value, $statuses);
    }

    /** @return list<OrderStatus> */
    public static function processing(): array
    {
        return array_values(array_filter(OrderStatus::cases(), fn (OrderStatus $status) => $status->isProcessing()));
    }

    public static function attentionCount(): int
    {
        return (int) Cache::remember('admin:operations:orders-attention-count', 60, fn () => Order::query()
            ->whereIn('status', self::values(self::ATTENTION))
            ->count());
    }
}
