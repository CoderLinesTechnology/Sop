<?php

namespace App\Http\Middleware;

use App\Support\Runtime\SelfTrigger;
use App\Support\SecurityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Accepts only requests this application signed for itself (SelfTrigger). */
class VerifySelfTrigger
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! SelfTrigger::verify($request)) {
            SecurityLog::record('invalid_runtime_trigger', 'medium', ['path' => $request->path(), 'ip' => $request->ip()]);

            abort(403);
        }

        return $next($request);
    }
}
