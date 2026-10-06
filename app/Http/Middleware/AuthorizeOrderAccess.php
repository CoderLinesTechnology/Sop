<?php

namespace App\Http\Middleware;

use App\Domain\Orders\OrderAccess;
use App\Models\Order;
use App\Support\SecurityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorises access to a customer's order page without an account.
 *
 * Accepts (1) a valid signed link for the order's current access version
 * (which then grants the browser session), (2) an existing session grant, or
 * (3) a signed-in customer whose verified email matches the order. Anything
 * else gets the same "link expired" page whether or not the order exists, and
 * repeated failures are logged as possible enumeration.
 */
class AuthorizeOrderAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $order = $request->route('order');
        if (! $order instanceof Order) {
            $order = Order::query()->where('public_id', (string) $order)->first();
        }

        if ($order && $order->data_purged_at === null) {
            if (OrderAccess::hasValidLink($request, $order)) {
                OrderAccess::grant($request, $order);
                $request->route()?->setParameter('order', $order);

                return $next($request);
            }

            if (OrderAccess::canAccess($request, $order)) {
                $request->route()?->setParameter('order', $order);

                return $next($request);
            }
        }

        $key = 'order-access-failures:'.$request->ip();
        RateLimiter::hit($key, 3600);
        if (RateLimiter::attempts($key) === 20) {
            SecurityLog::record('order_link_enumeration', 'high', ['attempts' => 20]);
        }

        return response()->view('orders.link-expired', [], 403);
    }
}
