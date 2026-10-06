<?php

namespace App\Support\Runtime;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fire-and-forget, HMAC-signed requests to this application's own internal
 * routes. Long work (the AI pipeline) continues in a fresh PHP request with
 * its own time limit, without a queue worker. The receiving route answers
 * 202 at once and does the work after its response.
 */
final class SelfTrigger
{
    public const HEADER = 'X-Statementra-Trigger';

    private const MAX_SKEW_SECONDS = 300;

    public static function fire(string $routeName, array $parameters = []): bool
    {
        $path = route($routeName, $parameters, absolute: false);
        $base = rtrim((string) (config('statementra.runtime.loopback_url') ?: config('app.url')), '/');
        $timestamp = (string) now()->getTimestamp();

        try {
            $response = Http::withHeaders([
                self::HEADER => $timestamp.'.'.self::sign($timestamp, $path),
                'User-Agent' => 'Statementra-Runtime/1.0',
            ])
                ->connectTimeout(3)
                ->timeout(8)
                ->withoutRedirecting()
                ->withOptions(['verify' => (bool) config('statementra.runtime.loopback_verify_tls', true)])
                ->post($base.$path);

            if ($response->status() !== 202) {
                Log::warning('Self-trigger was not accepted', ['route' => $routeName, 'status' => $response->status()]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('Self-trigger failed; the heartbeat will continue this work', ['route' => $routeName, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public static function verify(Request $request): bool
    {
        $header = (string) $request->header(self::HEADER, '');
        if (! preg_match('/^(\d{9,12})\.([a-f0-9]{64})$/', $header, $m)) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $m[1]) > self::MAX_SKEW_SECONDS) {
            return false;
        }

        return hash_equals(self::sign($m[1], $request->getPathInfo()), $m[2]);
    }

    private static function sign(string $timestamp, string $path): string
    {
        $key = hash_hmac('sha256', 'statementra-runtime-trigger', (string) config('app.key'));

        return hash_hmac('sha256', $timestamp."\n".$path, $key);
    }
}
