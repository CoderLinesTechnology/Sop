<?php

namespace App\Domain\Maintenance;

use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Heartbeat task: trims operational logs that have no lasting value.
 * Audit logs, payments, payment events and refunds are kept (financial and
 * accountability records).
 */
class PruneOperationalData
{
    public function __invoke(): void
    {
        $analyticsDays = max(30, (int) Settings::get('analytics.retention_days', 395));

        $this->prune('analytics_events', 'created_at', now()->subDays($analyticsDays));
        $this->prune('security_events', 'created_at', now()->subDays(365));
        $this->prune('email_events', 'created_at', now()->subDays(180));
        $this->prune('customer_login_tokens', 'expires_at', now()->subDay());
        $this->prune('system_tasks', 'updated_at', now()->subDays(30), fn ($q) => $q->whereNotIn('name', array_keys((array) config('statementra.runtime.tasks', []))));
        $this->prune('notifications', 'created_at', now()->subDays(90), fn ($q) => $q->whereNotNull('read_at'));
    }

    /** Delete in small batches to keep each statement short on shared hosting. */
    private function prune(string $table, string $column, \DateTimeInterface $before, ?\Closure $scope = null): void
    {
        for ($i = 0; $i < 20; $i++) {
            $query = DB::table($table)->where($column, '<', $before);
            if ($scope) {
                $scope($query);
            }

            if ($query->limit(1000)->delete() < 1000) {
                return;
            }
        }
    }
}
