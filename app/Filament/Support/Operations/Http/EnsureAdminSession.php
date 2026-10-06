<?php

namespace App\Filament\Support\Operations\Http;

use App\Models\AdminUser;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the admin support routes the same way the panel does: guests are
 * sent to the admin sign-in page (instead of a missing customer "login"
 * route), and only active administrators with a role and multi-factor
 * authentication configured get through.
 */
class EnsureAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if (! $admin instanceof AdminUser) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Unauthenticated.'], 401)
                : redirect()->guest(Filament::getPanel('admin')->getLoginUrl());
        }

        abort_unless($admin->canAccessPanel(Filament::getPanel('admin')), 403);
        abort_unless($admin->hasMfaEnabled(), 403, 'Set up multi-factor authentication in the admin panel first.');

        return $next($request);
    }
}
