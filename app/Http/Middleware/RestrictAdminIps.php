<?php

namespace App\Http\Middleware;

use App\Support\SecurityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional defence in depth: when ADMIN_IP_ALLOWLIST is set (IPs or CIDR
 * ranges), the admin panel is unreachable from anywhere else.
 */
class RestrictAdminIps
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowlist = config('statementra.security.admin_ip_allowlist', []);

        if ($allowlist !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowlist)) {
            SecurityLog::record('admin_ip_blocked', 'medium', ['ip' => $request->ip()]);
            abort(404);
        }

        return $next($request);
    }
}
