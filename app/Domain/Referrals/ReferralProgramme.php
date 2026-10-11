<?php

namespace App\Domain\Referrals;

use App\Models\Referrer;
use App\Support\Settings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Settings and lookups shared by every part of the referral programme.
 *
 * The programme only records attribution: a referral code never changes a
 * price (prices come from PriceCalculator alone).
 *
 * Where a referral is remembered:
 *  - in the server-side session (the strictly necessary session cookie the
 *    site already sets) as soon as a referral link is followed, for this
 *    visit only;
 *  - in the first-party `st_ref` cookie (the code and nothing else) only
 *    after the visitor agrees in the consent prompt, and never when the
 *    browser sends Global Privacy Control.
 * The session wins over the cookie, so the last link followed counts.
 */
final class ReferralProgramme
{
    public const COOKIE = 'st_ref';

    /** The referral of this visit (session only). */
    public const SESSION_CODE = 'referral.code';

    /** A code waiting for the consent prompt (shown once per session per code). */
    public const SESSION_PROMPT = 'referral.prompt';

    /** Codes the prompt has already been shown for in this session. */
    public const SESSION_PROMPTED = 'referral.prompted';

    /** Link previews and crawlers do not count as visits. */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|headless|monitor|curl|wget|python|httpclient|lighthouse|pingdom|uptime|facebookexternalhit|whatsapp|embedly|okhttp|go-http-client|java\//i';

    public static function enabled(): bool
    {
        return (bool) Settings::get('referrals.enabled', true);
    }

    public static function cookieDays(): int
    {
        return max(1, min(365, (int) Settings::get('referrals.cookie_days', 30)));
    }

    /** Days an earning stays pending before it can be paid out (refund and revision window). */
    public static function holdDays(): int
    {
        return max(0, min(120, (int) Settings::get('referrals.hold_days', 14)));
    }

    /** Minimum payout, in minor units of the site currency. */
    public static function minimumPayout(): int
    {
        return max(1, (int) Settings::get('referrals.minimum_payout', 10000));
    }

    public static function applicationsOpen(): bool
    {
        return self::enabled() && (bool) Settings::get('referrals.applications_open', true);
    }

    public static function rejectedApplicationDays(): int
    {
        return max(7, min(3650, (int) Settings::get('referrals.rejected_application_days', 180)));
    }

    /** Days an approved application is kept (the partner record holds what the programme needs). */
    public static function approvedApplicationDays(): int
    {
        return max(30, min(3650, (int) Settings::get('referrals.approved_application_days', 365)));
    }

    /** Years partner money records keep personal data: after a payout, and after a partnership ends (accounting and tax). */
    public static function financialRecordYears(): int
    {
        return max(1, min(20, (int) Settings::get('referrals.financial_record_years', 6)));
    }

    /** A referral code in its stored form, or null when it cannot be one. */
    public static function normalize(mixed $code): ?string
    {
        if (! is_string($code) || strlen($code) > 100) {
            return null;
        }

        $code = Referrer::normalizeCode($code);

        return $code !== '' && strlen($code) <= 40 ? $code : null;
    }

    /** The referrer that may attribute new orders with this code, or null. */
    public static function activeReferrer(mixed $code): ?Referrer
    {
        $code = self::normalize($code);

        return $code === null ? null : Referrer::query()->active()->where('code', $code)->first();
    }

    /** The code remembered (with consent) from the last referral link this browser followed (not checked). */
    public static function cookieCode(Request $request): ?string
    {
        return self::normalize($request->cookie(self::COOKIE));
    }

    /** The code of the referral link followed in this session (not checked). */
    public static function sessionCode(Request $request): ?string
    {
        return $request->hasSession() ? self::normalize($request->session()->get(self::SESSION_CODE)) : null;
    }

    /** The code that attributes this visitor: this session's link first, then the consented cookie. */
    public static function linkCode(Request $request): ?string
    {
        return self::sessionCode($request) ?? self::cookieCode($request);
    }

    /** The active referrer behind this visitor's link (session first, then the consented cookie), or null. */
    public static function referrerForRequest(Request $request): ?Referrer
    {
        foreach (array_unique(array_filter([self::sessionCode($request), self::cookieCode($request)])) as $code) {
            if ($referrer = self::activeReferrer($code)) {
                return $referrer;
            }
        }

        return null;
    }

    /** The consented 30-day cookie (first-party, HttpOnly, SameSite=Lax, Secure on HTTPS). */
    public static function cookie(string $code, Request $request): Cookie
    {
        return cookie(
            self::COOKIE,
            $code,
            self::cookieDays() * 24 * 60,
            path: '/',
            secure: $request->isSecure() || app()->isProduction(),
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    /** Global Privacy Control: the visitor asked not to be tracked, so the referral stays in this session only. */
    public static function prefersPrivacy(Request $request): bool
    {
        return $request->header('Sec-GPC') === '1';
    }

    public static function isBot(Request $request): bool
    {
        $userAgent = (string) $request->userAgent();

        return $userAgent === '' || (bool) preg_match(self::BOT_PATTERN, $userAgent);
    }
}
