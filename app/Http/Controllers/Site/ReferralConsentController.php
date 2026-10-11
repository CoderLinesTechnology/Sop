<?php

namespace App\Http\Controllers\Site;

use App\Domain\Referrals\ReferralProgramme;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The answer to the referral consent prompt. "Remember" stores this
 * session's referral code in the 30-day `st_ref` cookie; "No thanks" keeps it
 * for this session only. Global Privacy Control always means session only.
 * Answers with 204 to the in-page (fetch) submit and redirects back to the
 * page otherwise.
 */
class ReferralConsentController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'string', 'in:remember,decline']]);

        $cookie = null;
        if (ReferralProgramme::enabled() && $data['decision'] === 'remember' && ! ReferralProgramme::prefersPrivacy($request)) {
            $referrer = ReferralProgramme::activeReferrer(ReferralProgramme::sessionCode($request));
            $cookie = $referrer ? ReferralProgramme::cookie($referrer->code, $request) : null;
        }

        $request->session()->forget(ReferralProgramme::SESSION_PROMPT);

        $response = $request->expectsJson()
            ? response()->noContent()
            : redirect()->to($this->previousPage($request), 303);
        $response->headers->set('Cache-Control', 'no-store, private');

        if ($cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    /** Back to the page the prompt was on, but only ever on this site. */
    private function previousPage(Request $request): string
    {
        $previous = (string) $request->session()->previousUrl();
        $root = rtrim(url('/'), '/');

        return $previous !== '' && ($previous === $root || str_starts_with($previous, $root.'/')) ? $previous : $root;
    }
}
