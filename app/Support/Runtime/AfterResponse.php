<?php

namespace App\Support\Runtime;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Runs work after the HTTP response has been sent: the request-driven
 * replacement for queued jobs (Statementra needs no queue worker or cron).
 *
 * PHP-FPM and LiteSpeed release the visitor's connection before the work
 * starts (fastcgi_finish_request / litespeed_finish_request), so customers
 * never wait for it. Every caller persists its intent in the database first
 * (an email row, a payment event, an AI job...), so work that dies with the
 * PHP process is picked up again by the heartbeat.
 *
 * In console contexts (artisan, tests) the work runs immediately, which keeps
 * behaviour deterministic.
 */
final class AfterResponse
{
    /** True once deferred work has started: the response is already sent. */
    private static bool $sent = false;

    private static bool $forceImmediate = false;

    public static function run(string $label, Closure $work, int $timeLimitSeconds = 900): void
    {
        $task = static function () use ($label, $work, $timeLimitSeconds): void {
            ignore_user_abort(true);
            if (function_exists('set_time_limit')) {
                @set_time_limit($timeLimitSeconds);
            }

            try {
                $work();
            } catch (Throwable $e) {
                report($e);
                Log::error('After-response task failed', ['task' => $label, 'error' => $e->getMessage()]);
            }
        };

        // Laravel invokes deferred callbacks from a snapshot, so work deferred
        // while deferred work is already running would never run: run it now,
        // but never inside an open transaction (long work would hold its locks,
        // and a rollback must not leave side effects behind).
        if (self::$sent || self::$forceImmediate || app()->runningInConsole()) {
            DB::transactionLevel() > 0 ? DB::afterCommit($task) : $task();

            return;
        }

        defer(static function () use ($task): void {
            self::$sent = true;
            $task();
        }, $label, always: true);
    }

    /** Make run() execute work inline (tests that exercise HTTP flows outside the console). */
    public static function runImmediately(bool $immediate = true): void
    {
        self::$forceImmediate = $immediate;
    }
}
