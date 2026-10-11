<?php

namespace App\View\Components;

use App\Domain\Referrals\ReferralProgramme;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\View\Component;

/**
 * The small "May we remember this referral?" prompt shown on the page after
 * a referral link was followed: at most once per session per code, never
 * with Global Privacy Control, and never when the cookie already holds the
 * code. Showing it consumes the queued prompt, so a reload does not show it
 * again. Remember / No thanks post to ReferralConsentController.
 */
class ReferralConsentPrompt extends Component
{
    public ?string $code = null;

    public function __construct(private readonly Request $request) {}

    public function shouldRender(): bool
    {
        if (! $this->request->hasSession() || ! ReferralProgramme::enabled()) {
            return false;
        }

        $session = $this->request->session();
        $code = ReferralProgramme::normalize($session->get(ReferralProgramme::SESSION_PROMPT));
        if ($code === null) {
            return false;
        }

        $session->forget(ReferralProgramme::SESSION_PROMPT);
        $prompted = (array) $session->get(ReferralProgramme::SESSION_PROMPTED, []);
        if (in_array($code, $prompted, true) || ReferralProgramme::prefersPrivacy($this->request) || ReferralProgramme::cookieCode($this->request) === $code) {
            return false;
        }

        $session->put(ReferralProgramme::SESSION_PROMPTED, array_slice([...$prompted, $code], -10));
        $this->code = $code;

        return true;
    }

    public function render(): View
    {
        return view('components.site.referral-consent', [
            'days' => ReferralProgramme::cookieDays(),
            'siteName' => Settings::siteName(),
        ]);
    }
}
