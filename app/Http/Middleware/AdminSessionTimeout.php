<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Signs administrators out after a configurable period of inactivity. */
class AdminSessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $minutes = max(5, (int) Settings::get('security.admin_session_minutes', 120));
        $last = (int) $request->session()->get('admin_last_activity', 0);

        if ($last > 0 && (time() - $last) > $minutes * 60) {
            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to(Filament::getLoginUrl())->with('status', 'You were signed out after a period of inactivity.');
        }

        $request->session()->put('admin_last_activity', time());

        return $next($request);
    }
}
