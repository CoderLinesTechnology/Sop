<?php

namespace App\Support\Runtime;

use App\Models\SystemTask;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The request-driven replacement for cron. Maintenance tasks registered in
 * config('statementra.runtime.tasks') run when a heartbeat happens:
 *
 *  - after ordinary page views (at most once a minute, after the response
 *    is sent, so visitors never wait for it);
 *  - when anything calls the secret ping URL /system/heartbeat/{token}
 *    (optional: any free uptime monitor works, no server access needed);
 *  - when `php artisan statementra:heartbeat` is run by hand.
 *
 * Each task runs at most once per interval: it is claimed with a conditional
 * update on its system_tasks row, so overlapping heartbeats never run the
 * same task twice. A beat stops starting new tasks once its time budget is
 * spent; whatever is left runs on the next beat.
 */
class Heartbeat
{
    private const STAMP_FILE = 'framework/heartbeat.stamp';

    /** Cheap per-request check (a file timestamp, no database query). */
    public function isDue(): bool
    {
        $stamp = @filemtime(storage_path(self::STAMP_FILE));

        return $stamp === false || $stamp <= time() - (int) config('statementra.runtime.heartbeat_min_gap_seconds', 60);
    }

    /**
     * Run every task that is due, within the time budget.
     *
     * @return array<string, string> task name => outcome (ran, failed, skipped)
     */
    public function beat(?int $budgetSeconds = null): array
    {
        @touch(storage_path(self::STAMP_FILE));

        $budgetSeconds ??= (int) config('statementra.runtime.heartbeat_budget_seconds', 25);
        $lock = Cache::lock('runtime:heartbeat', $budgetSeconds + 120);
        if (! $lock->get()) {
            return [];
        }

        $deadline = microtime(true) + $budgetSeconds;
        $outcomes = [];

        try {
            Cache::forever('runtime:last_heartbeat_at', now()->toIso8601String());
            if (Cache::add('runtime:heartbeat_persisted', true, 600)) {
                Settings::set('system.last_heartbeat_at', now()->toIso8601String());
            }

            foreach ($this->tasks() as $name => [$every, $class]) {
                if (microtime(true) >= $deadline) {
                    $outcomes[$name] = 'deferred';

                    continue;
                }

                $outcomes[$name] = $this->runIfDue($name, (int) $every, $class);
            }
        } finally {
            $lock->release();
        }

        return $outcomes;
    }

    /** Run one task now regardless of its interval (admin "run now", tests). */
    public function runNow(string $name): string
    {
        [$every, $class] = $this->tasks()[$name] ?? throw new InvalidArgumentException("Unknown heartbeat task [{$name}].");
        $this->row($name, (int) $every)->forceFill(['last_started_at' => now()])->save();

        return $this->execute($name, $class);
    }

    /** @return array<string, array{0:int, 1:class-string}> */
    public function tasks(): array
    {
        return (array) config('statementra.runtime.tasks', []);
    }

    public static function lastBeatAt(): ?string
    {
        return Cache::get('runtime:last_heartbeat_at') ?? Settings::get('system.last_heartbeat_at');
    }

    /** Secret for the external ping URL; generated on first use and kept in settings. */
    public static function token(): string
    {
        $token = (string) (config('statementra.runtime.heartbeat_token') ?: Settings::get('system.heartbeat_token'));
        if ($token === '') {
            $token = Str::random(40);
            Settings::set('system.heartbeat_token', $token);
        }

        return $token;
    }

    private function runIfDue(string $name, int $every, string $class): string
    {
        $row = $this->row($name, $every);

        $claimed = SystemTask::query()->whereKey($row->id)
            ->where(fn ($q) => $q->whereNull('last_started_at')->orWhere('last_started_at', '<=', now()->subSeconds($every)))
            ->update(['last_started_at' => now(), 'interval_seconds' => $every, 'updated_at' => now()]);

        return $claimed === 1 ? $this->execute($name, $class) : 'skipped';
    }

    private function execute(string $name, string $class): string
    {
        $started = microtime(true);

        try {
            app()->call([app($class), '__invoke']);
            $status = 'ok';
            $error = null;
        } catch (Throwable $e) {
            report($e);
            Log::error('Heartbeat task failed', ['task' => $name, 'error' => $e->getMessage()]);
            $status = 'failed';
            $error = mb_substr($e->getMessage(), 0, 2000);
        }

        SystemTask::query()->where('name', $name)->update([
            'last_finished_at' => now(),
            'last_status' => $status,
            'last_error' => $error,
            'last_duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'run_count' => DB::raw('run_count + 1'),
            'failure_count' => DB::raw('failure_count + '.($status === 'failed' ? 1 : 0)),
            'updated_at' => now(),
        ]);

        return $status === 'ok' ? 'ran' : 'failed';
    }

    private function row(string $name, int $every): SystemTask
    {
        return SystemTask::query()->firstOrCreate(['name' => $name], ['interval_seconds' => $every]);
    }
}
