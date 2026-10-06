<?php

namespace App\Http\Controllers\Site;

use App\Domain\Email\TransactionalMailer;
use App\Enums\EmailTemplateKey;
use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\NewsletterSubscriber;
use App\Support\Analytics;
use App\Support\SpamGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Double opt-in newsletter. Responses never reveal whether an email is already subscribed. */
class NewsletterController extends Controller
{
    public function subscribe(Request $request, TransactionalMailer $mailer): RedirectResponse
    {
        $message = "Thanks! Please check your inbox to confirm your subscription.";

        if (SpamGuard::isBot($request)) {
            return back()->with('newsletter_status', $message);
        }

        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);
        $email = Str::lower(trim($data['email']));

        $subscriber = NewsletterSubscriber::query()->firstOrNew(['email' => $email]);
        if ($subscriber->exists && $subscriber->status === 'confirmed') {
            return back()->with('newsletter_status', $message);
        }

        $confirmToken = Str::random(48);
        $subscriber->fill([
            'status' => 'pending',
            'confirm_token_hash' => hash('sha256', $confirmToken),
            'unsubscribe_token_hash' => $subscriber->unsubscribe_token_hash ?? hash('sha256', Str::random(48)),
            'source' => mb_substr((string) $request->input('source', 'website'), 0, 40),
            'ip_address' => $request->ip(),
            'consented_at' => now(),
        ])->save();

        $mailer->send(EmailTemplateKey::NewsletterConfirm, $email, [
            'confirm_link' => route('newsletter.confirm', $confirmToken),
        ]);

        Analytics::record(AnalyticsEvent::NEWSLETTER_SIGNUP, $request);

        return back()->with('newsletter_status', $message);
    }

    public function confirm(string $token): View
    {
        $subscriber = NewsletterSubscriber::query()->where('confirm_token_hash', hash('sha256', $token))->first();

        if ($subscriber) {
            $unsubscribe = Str::random(48);
            $subscriber->forceFill([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'confirm_token_hash' => null,
                'unsubscribe_token_hash' => hash('sha256', $unsubscribe),
            ])->save();
        }

        return view('site.newsletter.result', [
            'title' => $subscriber ? "You're subscribed" : 'Link expired',
            'message' => $subscriber
                ? "Thanks for confirming. We'll send you helpful guides and application tips — no spam."
                : 'This confirmation link is invalid or has already been used.',
        ]);
    }

    public function unsubscribe(string $token): View
    {
        $subscriber = NewsletterSubscriber::query()->where('unsubscribe_token_hash', hash('sha256', $token))->first();
        $subscriber?->forceFill(['status' => 'unsubscribed', 'unsubscribed_at' => now()])->save();

        return view('site.newsletter.result', [
            'title' => 'You have been unsubscribed',
            'message' => "You won't receive our newsletter any more. You can subscribe again at any time.",
        ]);
    }
}
