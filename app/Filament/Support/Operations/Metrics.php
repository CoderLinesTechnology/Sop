<?php

namespace App\Filament\Support\Operations;

use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\RefundStatus;
use App\Models\AnalyticsEvent;
use App\Models\Service;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate business metrics for the dashboard and the Analytics page.
 *
 * Every figure is computed with aggregate SQL (no models are hydrated) and
 * cached for a minute. Money is reported in the site currency
 * (Settings::currency()); payments in other currencies are excluded from
 * money totals but still count as orders. The reporting window is a rolling
 * number of days ending now.
 */
final class Metrics
{
    public const CACHE_SECONDS = 60;

    /** Allowed reporting windows (days). */
    public const RANGES = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'];

    /** Payment statuses where money was captured. */
    private const CAPTURED = [
        PaymentRecordStatus::Success, PaymentRecordStatus::PartiallyRefunded, PaymentRecordStatus::Refunded,
    ];

    /** Payment statuses that made an order paid (including fully discounted orders). */
    private const PAID = [
        PaymentRecordStatus::Success, PaymentRecordStatus::PartiallyRefunded, PaymentRecordStatus::Refunded, PaymentRecordStatus::Waived,
    ];

    /** When a payment was made (older rows may lack paid_at). */
    private const PAID_AT = 'COALESCE(paid_at, verified_at, created_at)';

    public readonly int $days;

    public readonly string $currency;

    public readonly CarbonImmutable $from;

    public function __construct(int $days = 30, ?string $currency = null)
    {
        $this->days = array_key_exists($days, self::RANGES) ? $days : 30;
        $this->currency = strtoupper($currency ?? Settings::currency());
        $this->from = CarbonImmutable::now()->subDays($this->days);
    }

    /** Normalises a user-supplied range (e.g. from a filter) to an allowed window. */
    public static function range(mixed $days): int
    {
        $days = (int) $days;

        return array_key_exists($days, self::RANGES) ? $days : 30;
    }

    public function money(int $minor): string
    {
        return Format::money($minor, $this->currency);
    }

    /**
     * @return array{revenue_period:int, revenue_total:int, captured_orders_period:int, paid_orders_period:int, paid_orders_total:int, other_currencies:array<string,int>}
     */
    public function sales(): array
    {
        return $this->remember('sales', function (): array {
            $row = DB::table('payments')
                ->whereIn('status', self::values(self::PAID))
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN currency = ? AND status IN ('.self::placeholders(self::CAPTURED).') AND '.self::PAID_AT.' >= ? THEN amount ELSE 0 END), 0) AS revenue_period,'
                    .' COALESCE(SUM(CASE WHEN currency = ? AND status IN ('.self::placeholders(self::CAPTURED).') THEN amount ELSE 0 END), 0) AS revenue_total,'
                    .' COUNT(DISTINCT CASE WHEN purpose = ? AND currency = ? AND status IN ('.self::placeholders(self::CAPTURED).') AND '.self::PAID_AT.' >= ? THEN order_id END) AS captured_orders_period,'
                    .' COUNT(DISTINCT CASE WHEN purpose = ? AND '.self::PAID_AT.' >= ? THEN order_id END) AS paid_orders_period,'
                    .' COUNT(DISTINCT CASE WHEN purpose = ? THEN order_id END) AS paid_orders_total',
                    [
                        $this->currency, ...self::values(self::CAPTURED), $this->from,
                        $this->currency, ...self::values(self::CAPTURED),
                        'order', $this->currency, ...self::values(self::CAPTURED), $this->from,
                        'order', $this->from,
                        'order',
                    ],
                )
                ->first();

            $other = DB::table('payments')
                ->whereIn('status', self::values(self::CAPTURED))
                ->where('currency', '!=', $this->currency)
                ->whereRaw(self::PAID_AT.' >= ?', [$this->from])
                ->groupBy('currency')
                ->selectRaw('currency, SUM(amount) AS total')
                ->pluck('total', 'currency')
                ->map(fn ($total) => (int) $total)
                ->all();

            return [
                'revenue_period' => (int) $row->revenue_period,
                'revenue_total' => (int) $row->revenue_total,
                'captured_orders_period' => (int) $row->captured_orders_period,
                'paid_orders_period' => (int) $row->paid_orders_period,
                'paid_orders_total' => (int) $row->paid_orders_total,
                'other_currencies' => $other,
            ];
        });
    }

    /** Average value of orders paid in the window (site currency). */
    public function averageOrderValue(): ?int
    {
        $sales = $this->sales();

        return $sales['captured_orders_period'] > 0
            ? (int) round($sales['revenue_period'] / $sales['captured_orders_period'])
            : null;
    }

    /**
     * Current pipeline load plus orders created / delivered in the window.
     *
     * @return array{pending_payments:int, processing:int, failed:int, needs_information:int, submitted_period:int, delivered_period:int, delivered_total:int}
     */
    public function orders(): array
    {
        return $this->remember('orders', function (): array {
            $processing = OrderStatusGroups::values(OrderStatusGroups::processing());
            $failed = OrderStatusGroups::values(OrderStatusGroups::FAILED);
            $drafts = OrderStatusGroups::values(OrderStatusGroups::DRAFTS);

            $row = DB::table('orders')
                ->selectRaw(
                    'COALESCE(SUM(status = ?), 0) AS pending_payments,'
                    .' COALESCE(SUM(status IN ('.self::placeholders($processing).') AND paused_at IS NULL), 0) AS processing,'
                    .' COALESCE(SUM(status IN ('.self::placeholders($failed).')), 0) AS failed,'
                    .' COALESCE(SUM(status = ?), 0) AS needs_information,'
                    .' COALESCE(SUM(status NOT IN ('.self::placeholders($drafts).') AND created_at >= ?), 0) AS submitted_period,'
                    .' COALESCE(SUM(delivered_at >= ?), 0) AS delivered_period,'
                    .' COALESCE(SUM(delivered_at IS NOT NULL), 0) AS delivered_total',
                    [
                        OrderStatus::PaymentPending->value,
                        ...$processing,
                        ...$failed,
                        OrderStatus::NeedsInformation->value,
                        ...$drafts, $this->from,
                        $this->from,
                    ],
                )
                ->first();

            return array_map('intval', (array) $row);
        });
    }

    /** @return array{processed_count:int, processed_amount:int, open_count:int} */
    public function refunds(): array
    {
        return $this->remember('refunds', function (): array {
            $open = [RefundStatus::Requested->value, RefundStatus::Approved->value, RefundStatus::Processing->value];

            $row = DB::table('refunds')
                ->selectRaw(
                    'COALESCE(SUM(status = ? AND processed_at >= ?), 0) AS processed_count,'
                    .' COALESCE(SUM(CASE WHEN status = ? AND processed_at >= ? AND currency = ? THEN amount ELSE 0 END), 0) AS processed_amount,'
                    .' COALESCE(SUM(status IN ('.self::placeholders($open).')), 0) AS open_count',
                    [
                        RefundStatus::Processed->value, $this->from,
                        RefundStatus::Processed->value, $this->from, $this->currency,
                        ...$open,
                    ],
                )
                ->first();

            return array_map('intval', (array) $row);
        });
    }

    /** @return array{redemptions:int, discount:int, orders_with_coupon_rate:?float} */
    public function coupons(): array
    {
        return $this->remember('coupons', function (): array {
            $row = DB::table('coupon_redemptions')
                ->where('status', 'redeemed')
                ->where('redeemed_at', '>=', $this->from)
                ->selectRaw('COUNT(*) AS redemptions, COALESCE(SUM(CASE WHEN currency = ? THEN discount_amount ELSE 0 END), 0) AS discount', [$this->currency])
                ->first();

            $paid = $this->sales()['paid_orders_period'];

            return [
                'redemptions' => (int) $row->redemptions,
                'discount' => (int) $row->discount,
                'orders_with_coupon_rate' => $paid > 0 ? min(1, (int) $row->redemptions / $paid) : null,
            ];
        });
    }

    /** @return array{average_minutes:?float, delivered:int} paid → delivered, for orders delivered in the window */
    public function processingTime(): array
    {
        return $this->remember('processing-time', function (): array {
            $paidAt = DB::table('payments')
                ->where('purpose', 'order')
                ->whereIn('status', self::values(self::PAID))
                ->groupBy('order_id')
                ->selectRaw('order_id, MIN('.self::PAID_AT.') AS paid_at');

            $row = DB::table('orders')
                ->joinSub($paidAt, 'p', 'p.order_id', '=', 'orders.id')
                ->where('orders.delivered_at', '>=', $this->from)
                ->whereColumn('orders.delivered_at', '>=', 'p.paid_at')
                ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, p.paid_at, orders.delivered_at)) AS seconds, COUNT(*) AS delivered')
                ->first();

            return [
                'average_minutes' => $row->seconds !== null ? round((float) $row->seconds / 60, 1) : null,
                'delivered' => (int) $row->delivered,
            ];
        });
    }

    /**
     * Orders that hit a processing or delivery failure in the window (from
     * the status history), plus failed pipeline steps (including steps that
     * succeeded on retry).
     *
     * @return array{processing_failures:int, delivery_failures:int, failed_steps:int}
     */
    public function failures(): array
    {
        return $this->remember('failures', function (): array {
            $row = DB::table('order_status_histories')
                ->where('created_at', '>=', $this->from)
                ->whereIn('to_status', [OrderStatus::ProcessingFailed->value, OrderStatus::DeliveryFailed->value])
                ->selectRaw(
                    'COUNT(DISTINCT CASE WHEN to_status = ? THEN order_id END) AS processing_failures,'
                    .' COUNT(DISTINCT CASE WHEN to_status = ? THEN order_id END) AS delivery_failures',
                    [OrderStatus::ProcessingFailed->value, OrderStatus::DeliveryFailed->value],
                )
                ->first();

            $failedSteps = DB::table('ai_job_steps')
                ->where('status', 'failed')
                ->where('created_at', '>=', $this->from)
                ->count();

            return [
                'processing_failures' => (int) $row->processing_failures,
                'delivery_failures' => (int) $row->delivery_failures,
                'failed_steps' => $failedSteps,
            ];
        });
    }

    /** Delivered / (delivered + orders whose delivery failed) in the window. */
    public function deliverySuccessRate(): ?float
    {
        $delivered = $this->orders()['delivered_period'];
        $failed = $this->failures()['delivery_failures'];

        return ($delivered + $failed) > 0 ? $delivered / ($delivered + $failed) : null;
    }

    /** @return array{average:?float, count:int, positive_rate:?float} */
    public function satisfaction(): array
    {
        return $this->remember('satisfaction', function (): array {
            $row = DB::table('feedback')
                ->where('created_at', '>=', $this->from)
                ->selectRaw('AVG(rating) AS average, COUNT(*) AS total, COALESCE(SUM(rating >= 4), 0) AS positive')
                ->first();

            $total = (int) $row->total;

            return [
                'average' => $total > 0 ? round((float) $row->average, 2) : null,
                'count' => $total,
                'positive_rate' => $total > 0 ? (int) $row->positive / $total : null,
            ];
        });
    }

    /** @return array{rate:?float, delivered:int, with_revisions:int} share of orders delivered in the window that requested a revision */
    public function revisionRate(): array
    {
        return $this->remember('revision-rate', function (): array {
            $row = DB::table('orders')
                ->where('delivered_at', '>=', $this->from)
                ->selectRaw('COUNT(*) AS delivered, COALESCE(SUM(EXISTS (SELECT 1 FROM revisions WHERE revisions.order_id = orders.id)), 0) AS with_revisions')
                ->first();

            $delivered = (int) $row->delivered;

            return [
                'rate' => $delivered > 0 ? (int) $row->with_revisions / $delivered : null,
                'delivered' => $delivered,
                'with_revisions' => (int) $row->with_revisions,
            ];
        });
    }

    /**
     * Daily revenue (site currency) and paid orders.
     *
     * @return array{labels:list<string>, revenue:list<float>, orders:list<int>}
     */
    public function dailyTrend(): array
    {
        return $this->remember('daily-trend', function (): array {
            $start = CarbonImmutable::today()->subDays($this->days - 1);

            $rows = DB::table('payments')
                ->whereIn('status', self::values(self::PAID))
                ->whereRaw(self::PAID_AT.' >= ?', [$start])
                ->groupByRaw('DATE('.self::PAID_AT.')')
                ->selectRaw(
                    'DATE('.self::PAID_AT.') AS day,'
                    .' COALESCE(SUM(CASE WHEN currency = ? AND status IN ('.self::placeholders(self::CAPTURED).') THEN amount ELSE 0 END), 0) AS revenue,'
                    .' COUNT(DISTINCT CASE WHEN purpose = ? THEN order_id END) AS orders',
                    [$this->currency, ...self::values(self::CAPTURED), 'order'],
                )
                ->get()
                ->keyBy(fn ($row) => (string) $row->day);

            $labels = [];
            $revenue = [];
            $orders = [];
            for ($day = $start; $day->lte(CarbonImmutable::today()); $day = $day->addDay()) {
                $row = $rows->get($day->toDateString());
                $labels[] = $day->format('j M');
                $revenue[] = round(((int) ($row?->revenue ?? 0)) / 100, 2);
                $orders[] = (int) ($row?->orders ?? 0);
            }

            return ['labels' => $labels, 'revenue' => $revenue, 'orders' => $orders];
        });
    }

    /** @return array<string, int> order status value => count, for orders created in the window (drafts excluded) */
    public function ordersByStatus(): array
    {
        return $this->remember('orders-by-status', fn (): array => DB::table('orders')
            ->where('created_at', '>=', $this->from)
            ->whereNotIn('status', OrderStatusGroups::values(OrderStatusGroups::DRAFTS))
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS total')
            ->orderByDesc('total')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all());
    }

    /**
     * Services ranked by paid orders in the window, with views from analytics.
     *
     * @return list<array{service_id:int, name:string, orders:int, revenue:int, views:int, conversion:?float}>
     */
    public function popularServices(int $limit = 10): array
    {
        return $this->remember('popular-services:'.$limit, function () use ($limit): array {
            $rows = DB::table('payments')
                ->join('orders', 'orders.id', '=', 'payments.order_id')
                ->where('payments.purpose', 'order')
                ->whereIn('payments.status', self::values(self::PAID))
                ->whereRaw('COALESCE(payments.paid_at, payments.verified_at, payments.created_at) >= ?', [$this->from])
                ->groupBy('orders.service_id')
                ->selectRaw(
                    'orders.service_id, COUNT(DISTINCT orders.id) AS orders,'
                    .' COALESCE(SUM(CASE WHEN payments.currency = ? AND payments.status IN ('.self::placeholders(self::CAPTURED).') THEN payments.amount ELSE 0 END), 0) AS revenue',
                    [$this->currency, ...self::values(self::CAPTURED)],
                )
                ->orderByDesc('orders')
                ->limit($limit)
                ->get();

            $views = DB::table('analytics_events')
                ->where('event', AnalyticsEvent::SERVICE_VIEW)
                ->where('created_at', '>=', $this->from)
                ->whereNotNull('service_id')
                ->groupBy('service_id')
                ->selectRaw('service_id, COUNT(DISTINCT COALESCE(visitor_hash, CONCAT(\'e:\', id))) AS views')
                ->pluck('views', 'service_id');

            $serviceIds = $rows->pluck('service_id')->merge($views->keys())->unique()->all();
            $names = Service::withTrashed()->whereIn('id', $serviceIds)->pluck('name', 'id');

            $byService = [];
            foreach ($rows as $row) {
                $byService[(int) $row->service_id] = ['orders' => (int) $row->orders, 'revenue' => (int) $row->revenue];
            }

            $result = [];
            foreach (array_unique([...array_keys($byService), ...$views->keys()->map(fn ($id) => (int) $id)->all()]) as $serviceId) {
                $orders = $byService[$serviceId]['orders'] ?? 0;
                $viewCount = (int) ($views[$serviceId] ?? 0);
                $result[] = [
                    'service_id' => $serviceId,
                    'name' => (string) ($names[$serviceId] ?? 'Service #'.$serviceId),
                    'orders' => $orders,
                    'revenue' => $byService[$serviceId]['revenue'] ?? 0,
                    'views' => $viewCount,
                    'conversion' => $viewCount > 0 ? min(1, $orders / $viewCount) : null,
                ];
            }

            usort($result, fn (array $a, array $b) => [$b['orders'], $b['views']] <=> [$a['orders'], $a['views']]);

            return array_slice($result, 0, $limit);
        });
    }

    /**
     * Conversion funnel from first-party analytics. Page views count unique
     * (daily-rotating) visitor hashes; later steps count unique orders, or
     * visitors when no order exists yet.
     *
     * @return list<array{event:string, label:string, count:int, step_rate:?float, overall_rate:?float}>
     */
    public function funnel(): array
    {
        return $this->remember('funnel', function (): array {
            $steps = [
                AnalyticsEvent::PAGE_VIEW => 'Visitors',
                AnalyticsEvent::SERVICE_VIEW => 'Viewed a service',
                AnalyticsEvent::FORM_START => 'Started the order form',
                AnalyticsEvent::FORM_COMPLETE => 'Completed the order form',
                AnalyticsEvent::CHECKOUT_START => 'Started checkout',
                AnalyticsEvent::PAYMENT_SUCCESS => 'Paid',
            ];

            $counts = DB::table('analytics_events')
                ->where('created_at', '>=', $this->from)
                ->whereIn('event', [...array_keys($steps), AnalyticsEvent::PAYMENT_FAILED])
                ->groupBy('event')
                ->selectRaw(
                    'event, COUNT(DISTINCT visitor_hash) AS visitors,'
                    ." COUNT(DISTINCT CASE WHEN order_id IS NOT NULL THEN CONCAT('o:', order_id)"
                    ." WHEN visitor_hash IS NOT NULL THEN CONCAT('v:', visitor_hash) ELSE CONCAT('e:', id) END) AS actors"
                )
                ->get()
                ->keyBy('event');

            $funnel = [];
            $first = null;
            $previous = null;
            foreach ($steps as $event => $label) {
                $row = $counts->get($event);
                $count = (int) ($event === AnalyticsEvent::PAGE_VIEW ? ($row?->visitors ?? 0) : ($row?->actors ?? 0));
                $first ??= $count;

                $funnel[] = [
                    'event' => $event,
                    'label' => $label,
                    'count' => $count,
                    'step_rate' => $previous === null ? null : ($previous > 0 ? $count / $previous : null),
                    'overall_rate' => $first > 0 ? $count / $first : null,
                ];
                $previous = $count;
            }

            $failed = (int) ($counts->get(AnalyticsEvent::PAYMENT_FAILED)?->actors ?? 0);
            $funnel[] = [
                'event' => AnalyticsEvent::PAYMENT_FAILED,
                'label' => 'Payment failed',
                'count' => $failed,
                'step_rate' => null,
                'overall_rate' => null,
            ];

            return $funnel;
        });
    }

    /** Forget cached figures (e.g. in tests or after a data repair). */
    public static function flush(): void
    {
        foreach (array_keys(self::RANGES) as $days) {
            foreach (['sales', 'orders', 'refunds', 'coupons', 'processing-time', 'failures', 'satisfaction', 'revision-rate', 'daily-trend', 'orders-by-status', 'funnel', 'popular-services:10', 'popular-services:5'] as $key) {
                Cache::forget(self::cacheKey($key, $days, Settings::currency()));
            }
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function remember(string $key, Closure $callback): mixed
    {
        return Cache::remember(self::cacheKey($key, $this->days, $this->currency), self::CACHE_SECONDS, $callback);
    }

    private static function cacheKey(string $key, int $days, string $currency): string
    {
        return "admin:metrics:{$key}:{$days}:{$currency}";
    }

    /** @param  list<\BackedEnum|string>  $values
     *  @return list<string> */
    private static function values(array $values): array
    {
        return array_map(fn ($value) => $value instanceof \BackedEnum ? (string) $value->value : (string) $value, $values);
    }

    /** @param  array<mixed>  $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }
}
