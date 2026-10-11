<?php

namespace App\Http\Middleware;

use App\Domain\Referrals\ReferralProgramme;
use App\Domain\Referrals\ReferralVisits;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Referral links: "?ref=CODE" on any page.
 *
 * For an active referrer's code it remembers the referral in the
 * server-side session for this visit (no new device storage), counts the
 * visit and queues the consent prompt that offers to remember it for longer
 * (the `st_ref` cookie is set only from that prompt, never here, and the
 * prompt is skipped for Global Privacy Control). Unknown or inactive codes
 * are ignored silently. Either way the visitor is sent (302) to the same URL
 * without "ref", so every page keeps one canonical address and the code does
 * not linger in the address bar, history or shared links. This runs in the
 * web group, before any controller (and so before the SEO redirects that
 * canonicalise slugs).
 */
class CaptureReferral
{
    public function __construct(private readonly ReferralVisits $visits) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || ! $request->query->has('ref') || $request->expectsJson()) {
            return $next($request);
        }

        try {
            if (! ReferralProgramme::enabled()) {
                return $next($request);
            }

            $redirect = new RedirectResponse($this->urlWithoutRef($request), 302);
            $redirect->headers->set('Cache-Control', 'no-store, private');

            $referrer = ReferralProgramme::activeReferrer($request->query('ref'));
            if ($referrer && $request->hasSession()) {
                $session = $request->session();
                $session->put(ReferralProgramme::SESSION_CODE, $referrer->code);

                $alreadyRemembered = ReferralProgramme::cookieCode($request) === $referrer->code;
                $alreadyAsked = in_array($referrer->code, (array) $session->get(ReferralProgramme::SESSION_PROMPTED, []), true);
                if (! $alreadyRemembered && ! $alreadyAsked && ! ReferralProgramme::prefersPrivacy($request)) {
                    $session->put(ReferralProgramme::SESSION_PROMPT, $referrer->code);
                }

                $this->visits->record($referrer, $request);
            }

            return $redirect;
        } catch (Throwable $e) {
            // A referral problem must never stop the visitor from seeing the page.
            report($e);

            return $next($request);
        }
    }

    /**
     * The same address on this site, minus "ref". Built from the trimmed path
     * on the app's root URL (APP_URL in production): url()->current() would
     * pass "//host/..." through as a protocol-relative URL (an open redirect).
     */
    private function urlWithoutRef(Request $request): string
    {
        $query = Arr::query(Arr::except($request->query(), ['ref']));

        return url('/'.trim($request->path(), '/')).($query !== '' ? '?'.$query : '');
    }
}
