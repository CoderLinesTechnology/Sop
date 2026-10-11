<?php

namespace App\Http\Controllers\Site;

use App\Domain\Catalogue\Catalogue;
use App\Domain\Referrals\PartnerApplications;
use App\Domain\Referrals\ReferralProgramme;
use App\Http\Controllers\Controller;
use App\Models\PartnerApplication;
use App\Support\Countries;
use App\Support\Seo;
use App\Support\SpamGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Become a partner": landing copy (CMS page "become-a-partner") and the
 * application form. Submitting never reveals whether an address applied
 * before; the application counts only once the applicant confirms their
 * email (GET shows the confirmation page, only the button confirms, so mail
 * scanners that open links cannot). No payment details are asked for.
 */
class PartnerApplicationController extends Controller
{
    private const SUBMITTED = 'Thanks for applying! Please check your inbox and confirm your email address so we can review your application.';

    public function show(Catalogue $catalogue): View
    {
        abort_unless(ReferralProgramme::enabled(), 404);

        $page = $catalogue->page('become-a-partner');

        return view('site.partners.apply', [
            'page' => $page,
            'open' => ReferralProgramme::applicationsOpen(),
            'countries' => Countries::all(),
            'channels' => PartnerApplication::CHANNELS,
            'audiences' => PartnerApplication::AUDIENCE_SIZES,
            'seo' => Seo::make($page?->seo_title ?: 'Become a Partner', $page?->seo_description)
                ->withBreadcrumbs([['Home', url('/')], ['Become a partner', route('partners.apply')]]),
        ]);
    }

    public function store(Request $request, PartnerApplications $applications): RedirectResponse
    {
        abort_unless(ReferralProgramme::enabled(), 404);

        if (! ReferralProgramme::applicationsOpen()) {
            return redirect()->route('partners.apply');
        }

        if (SpamGuard::isBot($request)) {
            return redirect()->to(route('partners.apply').'#apply')->with('status', self::SUBMITTED);
        }

        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9][0-9 ()\-]{6,38}$/'],
            'city' => ['required', 'string', 'max:80'],
            'country' => ['required', 'string', Rule::in(array_keys(Countries::all()))],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', Rule::in(array_keys(PartnerApplication::CHANNELS))],
            'channels_details' => [Rule::requiredIf(fn () => in_array('other', (array) $request->input('channels', []), true)), 'nullable', 'string', 'max:500'],
            'audience_size' => ['required', 'string', Rule::in(array_keys(PartnerApplication::AUDIENCE_SIZES))],
            'links' => ['nullable', 'array', 'max:3'],
            'links.*' => ['nullable', 'string', 'url:http,https', 'max:255'],
            'motivation' => ['required', 'string', 'min:20', 'max:2000'],
            'preferred_code' => ['nullable', 'string', 'max:40', 'regex:/^\s*[A-Za-z0-9_-]+\s*$/'],
            'consent' => ['accepted'],
        ], [
            'phone.regex' => 'Enter a phone number with digits (a leading + is fine).',
            'channels.required' => 'Choose at least one way you would share Statementra.',
            'channels_details.required' => 'Tell us briefly how else you would share Statementra.',
            'links.*.url' => 'Enter each link as a full web address starting with https://.',
            'motivation.min' => 'Tell us a little more (at least 20 characters).',
            'preferred_code.regex' => 'Use only letters, numbers, dashes and underscores.',
            'consent.accepted' => 'Please confirm you have read the privacy notice and agree to be contacted about your application.',
        ], [
            'full_name' => 'full name',
            'audience_size' => 'audience size',
        ]);

        $applications->submit($data);

        return redirect()->to(route('partners.apply').'#apply')->with('status', self::SUBMITTED);
    }

    public function confirmPage(PartnerApplications $applications, string $token): View
    {
        abort_unless(ReferralProgramme::enabled(), 404);
        $application = $applications->pendingFor($token);

        return view('site.partners.confirm', [
            'token' => $token,
            'state' => $application ? 'pending' : 'invalid',
            'seo' => Seo::make('Confirm your partner application', index: false),
        ]);
    }

    public function confirm(PartnerApplications $applications, string $token): View
    {
        abort_unless(ReferralProgramme::enabled(), 404);
        $application = $applications->confirm($token);

        return view('site.partners.confirm', [
            'token' => $token,
            'state' => $application ? 'confirmed' : 'invalid',
            'seo' => Seo::make('Confirm your partner application', index: false),
        ]);
    }
}
