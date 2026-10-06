<?php

namespace App\Filament\Support\Catalogue;

use App\Models\AdminUser;
use App\Models\SystemTask;
use App\Support\Audit;
use App\Support\Runtime\AfterResponse;
use App\Support\Runtime\Heartbeat;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Health of the request-driven runtime (no queue worker, no cron): the
 * maintenance tasks in config('statementra.runtime.tasks') run on a
 * heartbeat that follows ordinary site traffic and, optionally, an external
 * monitor calling /system/heartbeat/{token}. See App\Support\Runtime\Heartbeat.
 */
final class SystemHealth
{
    public const TOKEN_KEY = 'system.heartbeat_token';

    public const LAST_HEARTBEAT_KEY = 'system.last_heartbeat_at';

    /** A heartbeat older than this is reported as missing. */
    public const STALE_AFTER_MINUTES = 15;

    /** The token is pinned in the server environment (RUNTIME_HEARTBEAT_TOKEN) and cannot be changed here. */
    public static function tokenFromEnvironment(): bool
    {
        return filled(config('statementra.runtime.heartbeat_token'));
    }

    public static function token(): ?string
    {
        if (class_exists(Heartbeat::class)) {
            return Heartbeat::token(); // generated and stored on first use
        }

        $token = Settings::get(self::TOKEN_KEY);

        return is_string($token) && $token !== '' ? $token : null;
    }

    public static function pingUrl(): ?string
    {
        $token = self::token();

        return $token ? url('/system/heartbeat/'.$token) : null;
    }

    /** Replace the heartbeat token; the old ping URL stops working immediately. */
    public static function regenerateToken(?AdminUser $admin = null): string
    {
        $old = Settings::get(self::TOKEN_KEY);
        $token = Str::random(40);

        Settings::set(self::TOKEN_KEY, $token, $admin?->id);

        // Never write the token itself to the audit log.
        Audit::log(
            'settings.heartbeat_token_regenerated',
            'settings',
            [self::TOKEN_KEY => is_string($old) && $old !== '' ? '…'.substr($old, -4) : null],
            [self::TOKEN_KEY => '…'.substr($token, -4)],
            [],
            $admin,
        );

        return $token;
    }

    public static function lastHeartbeat(): ?CarbonInterface
    {
        $candidates = [
            class_exists(Heartbeat::class) ? Heartbeat::lastBeatAt() : null,
            Settings::get(self::LAST_HEARTBEAT_KEY),
        ];

        $latest = null;
        foreach ($candidates as $value) {
            $date = self::date($value);
            if ($date && (! $latest || $date->gt($latest))) {
                $latest = $date;
            }
        }

        return $latest;
    }

    public static function heartbeatIsStale(): bool
    {
        $last = self::lastHeartbeat();

        return $last === null || $last->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /** @return array<string, string> configured task name => schedule label */
    public static function configuredTasks(): array
    {
        $tasks = [];
        foreach ((array) config('statementra.runtime.tasks', []) as $name => $definition) {
            $tasks[(string) $name] = $name.' (every '.self::humanInterval((int) ($definition[0] ?? 0)).')';
        }

        return $tasks;
    }

    public static function tasksAvailable(): bool
    {
        try {
            return Schema::hasTable('system_tasks');
        } catch (Throwable) {
            return false;
        }
    }

    /** @return Collection<int, SystemTask> */
    public static function tasks(): Collection
    {
        return self::tasksAvailable() ? SystemTask::query()->orderBy('name')->get() : collect();
    }

    /** Start a task now, after the response is sent (no queue, no cron). */
    public static function runTaskNow(string $name, ?AdminUser $admin = null): void
    {
        if (! array_key_exists($name, self::configuredTasks())) {
            throw new InvalidArgumentException("Unknown system task [{$name}].");
        }

        Audit::log('system.task_run', 'system_task', null, null, ['task' => $name], $admin);

        AfterResponse::run('admin.run-task', fn () => app(Heartbeat::class)->runNow($name));
    }

    public static function heartbeatSummary(): HtmlString
    {
        $last = self::lastHeartbeat();
        $color = self::heartbeatIsStale() ? 'var(--warning-600)' : 'var(--success-600)';
        $text = $last
            ? 'Last heartbeat '.e($last->diffForHumans()).' ('.e($last->format('j M Y H:i:s')).')'
            : 'No heartbeat recorded yet';

        $hint = self::heartbeatIsStale()
            ? '<div style="margin-top: .25rem; color: var(--gray-600);">The heartbeat follows site traffic. When the site is quiet, background work (sending emails, payment reconciliation, reminders, expiries) waits; point an uptime monitor at the ping URL every few minutes to keep it moving.</div>'
            : '';

        return new HtmlString('<div><strong style="color: '.$color.';">'.$text.'</strong>'.$hint.'</div>');
    }

    public static function tasksTable(): HtmlString
    {
        if (! self::tasksAvailable()) {
            return new HtmlString('<p style="color: var(--gray-500);">Task health appears here once the system tasks table exists (run the migrations).</p>');
        }

        $tasks = self::tasks()->keyBy('name');
        $configured = (array) config('statementra.runtime.tasks', []);
        $names = array_values(array_unique([...array_keys($configured), ...$tasks->keys()->all()]));

        if ($names === []) {
            return new HtmlString('<p style="color: var(--gray-500);">No system tasks are configured.</p>');
        }

        $th = 'text-align: left; font-weight: 500; color: var(--gray-500); padding: .4rem .6rem; border-bottom: 1px solid var(--gray-200); white-space: nowrap;';
        $td = 'padding: .4rem .6rem; border-bottom: 1px solid var(--gray-100); vertical-align: top;';

        $rows = '';
        foreach ($names as $name) {
            /** @var SystemTask|null $task */
            $task = $tasks->get($name);
            $interval = (int) ($task?->interval_seconds ?? ($configured[$name][0] ?? 0));
            $status = strtolower((string) ($task?->last_status ?? ''));
            $overdue = $task ? $task->isOverdue() : true;

            $statusColor = match (true) {
                $status === 'failed' => 'var(--danger-600)',
                $overdue => 'var(--warning-600)',
                $status === 'ok' => 'var(--success-600)',
                default => 'var(--gray-600)',
            };
            $statusLabel = match ($status) {
                'ok' => 'OK',
                '' => 'Never run',
                default => ucfirst($status),
            }.($overdue && $status !== '' ? ' · overdue' : '');

            $rows .= '<tr>'
                .'<td style="'.$td.' font-family: ui-monospace, monospace;">'.e($name).'</td>'
                .'<td style="'.$td.'">'.($interval > 0 ? 'every '.e(self::humanInterval($interval)) : '—').'</td>'
                .'<td style="'.$td.'">'.($task?->last_started_at ? e($task->last_started_at->diffForHumans()) : '—').'</td>'
                .'<td style="'.$td.'">'.($task?->last_duration_ms !== null ? e(number_format((int) $task->last_duration_ms)).' ms' : '—').'</td>'
                .'<td style="'.$td.' color: '.$statusColor.'; font-weight: 600;">'.e($statusLabel).'</td>'
                .'<td style="'.$td.'">'.e((string) (int) ($task?->run_count ?? 0)).((int) ($task?->failure_count ?? 0) > 0 ? ' <span style="color: var(--danger-600);">('.(int) $task->failure_count.' failed)</span>' : '').'</td>'
                .'<td style="'.$td.' color: var(--gray-600); max-width: 24rem;">'.e(Str::limit((string) ($task?->last_error ?? ''), 200)).'</td>'
                .'</tr>';
        }

        return new HtmlString('<div style="overflow-x: auto;"><table style="width: 100%; border-collapse: collapse; font-size: .875rem;"><thead><tr>'
            .'<th style="'.$th.'">Task</th><th style="'.$th.'">Schedule</th><th style="'.$th.'">Last run</th><th style="'.$th.'">Duration</th>'
            .'<th style="'.$th.'">Status</th><th style="'.$th.'">Runs</th><th style="'.$th.'">Last error</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table></div>');
    }

    private static function date(mixed $value): ?CarbonInterface
    {
        if (blank($value)) {
            return null;
        }

        try {
            return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function humanInterval(int $seconds): string
    {
        return match (true) {
            $seconds <= 0 => '—',
            $seconds % 86400 === 0 => ($seconds / 86400).' day'.($seconds === 86400 ? '' : 's'),
            $seconds % 3600 === 0 => ($seconds / 3600).' hour'.($seconds === 3600 ? '' : 's'),
            $seconds % 60 === 0 => ($seconds / 60).' minute'.($seconds === 60 ? '' : 's'),
            default => $seconds.' seconds',
        };
    }
}
