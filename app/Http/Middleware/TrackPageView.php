<?php

namespace App\Http\Middleware;

use App\Models\AnalyticsEvent;
use App\Support\Analytics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records a cookieless page view for successful public HTML GET requests and
 * remembers UTM parameters for the session (to attribute later conversions).
 */
class TrackPageView
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $key) {
                if (is_string($request->query($key)) && $request->query($key) !== '') {
                    $request->session()->put('utm.'.$key, mb_substr(strip_tags($request->query($key)), 0, 100));
                }
            }
        }

        $response = $next($request);

        if ($request->isMethod('GET')
            && $response->getStatusCode() === 200
            && ! $request->expectsJson()
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            Analytics::record(AnalyticsEvent::PAGE_VIEW, $request);
        }

        return $response;
    }
}
