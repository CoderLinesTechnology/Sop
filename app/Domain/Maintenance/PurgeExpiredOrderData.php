<?php

namespace App\Domain\Maintenance;

use App\Domain\Orders\OrderDataPurger;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Heartbeat task: erases personal content once an order's retention period
 * ends (orders.retention_days after delivery; after the last activity for
 * orders that never completed). Orders still in progress are never touched.
 */
class PurgeExpiredOrderData
{
    public function __construct(private readonly OrderDataPurger $purger) {}

    public function __invoke(): void
    {
        $retentionDays = max(7, (int) Settings::get('orders.retention_days', 90));
        $active = array_map(fn (OrderStatus $s) => $s->value, array_filter(
            OrderStatus::cases(),
            fn (OrderStatus $s) => $s->isProcessing() || in_array($s, [OrderStatus::NeedsInformation, OrderStatus::ManualReview], true),
        ));

        Order::query()
            ->whereNull('data_purged_at')
            ->whereNotIn('status', $active)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNotNull('retention_until')->where('retention_until', '<=', now()))
                ->orWhere(fn ($q) => $q->whereNull('retention_until')->where('updated_at', '<=', now()->subDays($retentionDays))))
            ->orderBy('id')
            ->limit(25)
            ->get()
            ->each(function (Order $order) {
                try {
                    $this->purger->purge($order, 'retention');
                } catch (Throwable $e) {
                    report($e);
                    Log::error('Retention purge failed', ['order' => $order->reference, 'error' => $e->getMessage()]);
                }
            });
    }
}
