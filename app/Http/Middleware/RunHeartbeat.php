<?php

namespace App\Http\Middleware;

use App\Support\Runtime\AfterResponse;
use App\Support\Runtime\Heartbeat;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets ordinary traffic drive maintenance (pseudo-cron): at most once a
 * minute, a request schedules a heartbeat to run after its response is sent.
 */
class RunHeartbeat
{
    public function __construct(private readonly Heartbeat $heartbeat) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (config('statementra.runtime.heartbeat_on_traffic', true) && $this->heartbeat->isDue()) {
            AfterResponse::run('heartbeat', fn () => $this->heartbeat->beat(), timeLimitSeconds: 300);
        }

        return $response;
    }
}
