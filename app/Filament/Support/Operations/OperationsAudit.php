<?php

namespace App\Filament\Support\Operations;

use App\Models\AuditLog;
use App\Models\Order;
use App\Support\Audit;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit helpers for admin operations.
 *
 * Several domain services already write their own audit entries (refunds,
 * link rotation, information requests, document resends, ...), others do
 * not. ensure() runs an operation and records an entry only when the
 * operation did not audit itself, so every admin action is logged exactly
 * once regardless of which layer owns the log line.
 */
final class OperationsAudit
{
    /** How long an "order.viewed" entry covers repeated views by the same admin. */
    private const VIEW_WINDOW_MINUTES = 60;

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @param  array<string, mixed>  $meta
     * @return T
     */
    public static function ensure(
        string $action,
        Model|string|null $target,
        Closure $operation,
        array $meta = [],
        ?array $before = null,
        ?array $after = null,
    ): mixed {
        $admin = AdminContext::user();
        $lastId = (int) AuditLog::query()->max('id');

        $result = $operation();

        $alreadyAudited = AuditLog::query()
            ->where('id', '>', $lastId)
            ->when($admin, fn ($query) => $query->where('admin_user_id', $admin->id))
            ->exists();

        if (! $alreadyAudited) {
            Audit::log($action, $target, $before, $after, $meta, $admin);
        }

        return $result;
    }

    /**
     * Viewing an order exposes customer data, so it is audited — at most once
     * per administrator per order per hour to keep the trail readable.
     */
    public static function orderViewed(Order $order): void
    {
        self::viewed('order.viewed', $order);
    }

    /** Log a view of a record holding customer data, at most once per admin per record per hour. */
    public static function viewed(string $action, Model $target): void
    {
        $admin = AdminContext::user();
        if (! $admin) {
            return;
        }

        $recentlyLogged = AuditLog::query()
            ->where('target_type', class_basename($target))
            ->where('target_id', (string) $target->getKey())
            ->where('action', $action)
            ->where('admin_user_id', $admin->id)
            ->where('created_at', '>=', now()->subMinutes(self::VIEW_WINDOW_MINUTES))
            ->exists();

        if (! $recentlyLogged) {
            Audit::log($action, $target, admin: $admin);
        }
    }
}
