<?php

namespace App\Http\Controllers\Site;

use App\Domain\Referrals\PartnerDashboard;
use App\Domain\Referrals\PaymentDetails;
use App\Domain\Referrals\ReferralPayouts;
use App\Domain\Referrals\ReferralProgramme;
use App\Domain\Referrals\ReferralRefused;
use App\Domain\Referrals\ReferrerPaymentDetails;
use App\Domain\Referrals\ReferrerService;
use App\Http\Controllers\Controller;
use App\Models\ReferralPayout;
use App\Models\Referrer;
use App\Support\Runtime\AfterResponse;
use App\Support\SecurityLog;
use App\Support\Seo;
use App\Support\Settings;
use App\Support\SpamGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Referral partners' private dashboard, payout requests, payment details and
 * the "email me my link" form.
 *
 * The dashboard link is the whole credential (no password): a 48-character
 * random token, looked up by its SHA-256 hash and compared in constant time.
 * Unknown, replaced and disabled links, and deactivated referrers, all get
 * the same 404. The pages are private (no-store, noindex, no-referrer; see
 * SecurityHeaders) and show counts and money only. Actions are POST with CSRF
 * and rate-limited; a payment-details change applies only once confirmed from
 * the referrer's email (ReferrerPaymentDetails).
 */
class PartnerController extends Controller
{
    public function show(Request $request, PartnerDashboard $dashboards, ReferralPayouts $payouts, string $token): View
    {
        $referrer = $this->referrer($request, $dashboards, $token);

        return view('site.partner.dashboard', [
            'token' => $token,
            'name' => $referrer->name,
            'code' => $referrer->code,
            'rate' => $referrer->formattedRate(),
            'shareUrl' => $referrer->shareUrl(),
            'cookieDays' => ReferralProgramme::cookieDays(),
            'supportEmail' => Settings::supportEmail(),
            'summary' => $dashboards->summary($referrer),
            'holdDays' => ReferralProgramme::holdDays(),
            'eligibility' => $payouts->eligibility($referrer),
            'payouts' => $dashboards->payouts($referrer),
            'paymentDetails' => PaymentDetails::mask($referrer->payment_details),
            'pendingDetails' => $referrer->pending_details_expires_at?->isFuture() ? PaymentDetails::mask($referrer->pending_payment_details) : null,
            'networks' => PaymentDetails::NETWORKS,
            'orders' => $dashboards->orders($referrer),
            // The canonical URL must not repeat the private token in the page's metadata.
            'seo' => new Seo(title: 'Your referral dashboard', canonical: route('partner.request'), index: false),
        ]);
    }

    /** "Request payout": the whole available balance, at most one open request. */
    public function requestPayout(Request $request, PartnerDashboard $dashboards, ReferralPayouts $payouts, string $token): RedirectResponse
    {
        $referrer = $this->referrer($request, $dashboards, $token);

        try {
            $payouts->request($referrer);
        } catch (ReferralRefused $e) {
            return $this->back($token)->withErrors(['payout' => $e->getMessage()]);
        }

        return $this->back($token, '#payouts')->with('partner_status', 'Your payout request was sent. We’ll email you when it has been paid.');
    }

    public function cancelPayout(Request $request, PartnerDashboard $dashboards, ReferralPayouts $payouts, string $token, string $payout): RedirectResponse
    {
        $referrer = $this->referrer($request, $dashboards, $token);
        $open = ReferralPayout::query()->where('public_id', strtoupper($payout))->where('referrer_id', $referrer->id)->first();
        if (! $open) {
            abort(404);
        }

        try {
            $payouts->cancel($referrer, $open);
        } catch (ReferralRefused $e) {
            return $this->back($token, '#payouts')->withErrors(['payout' => $e->getMessage()]);
        }

        return $this->back($token, '#payouts')->with('partner_status', 'Your payout request was cancelled. The amount is available again.');
    }

    /** Add or change payment details: stored as pending until confirmed from the referrer's email. */
    public function changePaymentDetails(Request $request, PartnerDashboard $dashboards, ReferrerPaymentDetails $details, string $token): RedirectResponse
    {
        $referrer = $this->referrer($request, $dashboards, $token);

        $validator = validator($request->all(), PaymentDetails::rules(), PaymentDetails::messages());
        if ($validator->fails()) {
            return $this->back($token, '#payment-details')->withErrors($validator)->withInput();
        }

        try {
            $details->requestChange($referrer, $validator->validated());
        } catch (ReferralRefused $e) {
            return $this->back($token, '#payment-details')->withErrors(['payment_method' => $e->getMessage()]);
        }

        return $this->back($token, '#payment-details')->with('partner_status', 'Almost done: we emailed you a link to confirm these payment details. They are used only after you confirm.');
    }

    /** The emailed link: shows the change; only the button (POST) applies it, so link scanners cannot. */
    public function confirmDetailsPage(ReferrerPaymentDetails $details, string $token): View
    {
        abort_unless(ReferralProgramme::enabled(), 404);
        $referrer = $details->pendingFor($token);

        return view('site.partner.confirm-details', [
            'token' => $token,
            'pending' => $referrer ? PaymentDetails::mask($referrer->pending_payment_details) : null,
            'confirmed' => false,
            'seo' => Seo::make('Confirm your payment details', index: false),
        ]);
    }

    public function confirmDetails(ReferrerPaymentDetails $details, string $token): View
    {
        abort_unless(ReferralProgramme::enabled(), 404);
        $referrer = $details->confirm($token);

        return view('site.partner.confirm-details', [
            'token' => $token,
            'pending' => null,
            'confirmed' => $referrer !== null,
            'current' => $referrer ? PaymentDetails::mask($referrer->payment_details) : null,
            'seo' => Seo::make('Confirm your payment details', index: false),
        ]);
    }

    public function request(): View
    {
        abort_unless(ReferralProgramme::enabled(), 404);

        return view('site.partner.request', ['seo' => Seo::make('Referral partner dashboard', index: false)]);
    }

    /** Same answer whether or not the address belongs to a referrer; the lookup and email run after the response. */
    public function send(Request $request, ReferrerService $referrers): RedirectResponse
    {
        abort_unless(ReferralProgramme::enabled(), 404);

        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:190']]);

        if (! SpamGuard::isBot($request)) {
            $email = (string) $data['email'];
            AfterResponse::run('partner-dashboard-link', fn () => $referrers->emailDashboardLinks($email), timeLimitSeconds: 60);
        }

        return redirect()->route('partner.request')
            ->with('status', 'If that address belongs to a referral partner, we have emailed the dashboard link to it. It may take a minute to arrive.');
    }

    /** The active referrer behind a dashboard token, or the same 404 for everything else (repeated misses are flagged). */
    private function referrer(Request $request, PartnerDashboard $dashboards, string $token): Referrer
    {
        abort_unless(ReferralProgramme::enabled(), 404);

        $referrer = $dashboards->findByToken($token);
        if (! $referrer) {
            $key = 'partner-link-misses:'.$request->ip();
            RateLimiter::hit($key, 3600);
            if (RateLimiter::attempts($key) === 10) {
                SecurityLog::record('partner_link_enumeration', 'medium', ['attempts' => 10]);
            }

            abort(404);
        }

        return $referrer;
    }

    private function back(string $token, string $fragment = ''): RedirectResponse
    {
        return redirect()->to(route('partner.dashboard', $token).$fragment, 303);
    }
}
