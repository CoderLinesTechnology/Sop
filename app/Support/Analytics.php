<?php

namespace App\Support;

use App\Models\AnalyticsEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * First-party, cookieless analytics.
 *
 * No third-party scripts, no cookies, no raw IP addresses stored. Visitors
 * are counted with a hash of IP + user agent + a salt that rotates daily, so
 * a visitor cannot be followed from one day to the next. Requests sending a
 * Global Privacy Control signal are counted without any visitor hash.
 */
final class Analytics
{
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|headless|monitor|curl|wget|python|httpclient|lighthouse|pingdom|uptime/i';

    public static function record(string $event, Request $request, array $attributes = []): void
    {
        if (! Settings::get('analytics.enabled', true)) {
            return;
        }

        $userAgent = (string) $request->userAgent();
        if ($userAgent === '' || preg_match(self::BOT_PATTERN, $userAgent)) {
            return;
        }

        $respectGpc = Settings::get('analytics.respect_gpc', true) && ($request->header('Sec-GPC') === '1' || $request->header('DNT') === '1');

        $referrerHost = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);
        if ($referrerHost === $request->getHost()) {
            $referrerHost = null;
        }

        self::insert($event, $attributes + [
            'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 500),
            'visitor_hash' => $respectGpc ? null : substr(hash('sha256', self::dailySalt().'|'.$request->ip().'|'.$userAgent), 0, 16),
            'referrer_host' => $referrerHost ? mb_substr($referrerHost, 0, 255) : null,
            'utm_source' => self::utm($request, 'utm_source'),
            'utm_medium' => self::utm($request, 'utm_medium'),
            'utm_campaign' => self::utm($request, 'utm_campaign'),
            'device' => self::device($userAgent),
            'country_code' => self::country($request),
        ]);
    }

    /** Events raised by the server without a browser request (webhooks, jobs). */
    public static function recordServer(string $event, array $attributes = []): void
    {
        if (! Settings::get('analytics.enabled', true)) {
            return;
        }

        self::insert($event, $attributes);
    }

    private static function insert(string $event, array $attributes): void
    {
        try {
            AnalyticsEvent::query()->create(array_intersect_key($attributes, array_flip([
                'path', 'service_id', 'order_id', 'visitor_hash', 'referrer_host', 'utm_source', 'utm_medium',
                'utm_campaign', 'device', 'country_code', 'value', 'meta',
            ])) + ['event' => $event, 'created_at' => now()]);
        } catch (Throwable $e) {
            Log::debug('Analytics insert failed', ['error' => $e->getMessage()]);
        }
    }

    private static function dailySalt(): string
    {
        return Cache::remember('analytics:salt:'.now()->format('Ymd'), now()->addHours(26), fn () => bin2hex(random_bytes(16)));
    }

    private static function utm(Request $request, string $key): ?string
    {
        $value = $request->query($key) ?? $request->session()?->get('utm.'.$key);

        return is_string($value) && $value !== '' ? mb_substr(strip_tags($value), 0, 100) : null;
    }

    private static function device(string $userAgent): string
    {
        return match (true) {
            (bool) preg_match('/ipad|tablet|kindle|silk/i', $userAgent) => 'tablet',
            (bool) preg_match('/mobi|android|iphone|ipod/i', $userAgent) => 'mobile',
            default => 'desktop',
        };
    }

    private static function country(Request $request): ?string
    {
        $code = strtoupper((string) ($request->header('CF-IPCountry') ?? $request->header('X-Country-Code') ?? ''));

        return preg_match('/^[A-Z]{2}$/', $code) && $code !== 'XX' ? $code : null;
    }
}
