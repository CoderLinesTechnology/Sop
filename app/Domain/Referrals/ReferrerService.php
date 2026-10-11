<?php

namespace App\Domain\Referrals;

use App\Domain\Email\TransactionalMailer;
use App\Enums\EmailTemplateKey;
use App\Models\AdminUser;
use App\Models\ReferralPayout;
use App\Models\Referrer;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Referrer lifecycle actions: the private dashboard link (email, replace,
 * disable), switching a referrer on or off, and erasing a referrer's personal
 * data. Every administrator action is audited; tokens never reach the audit log.
 */
final class ReferrerService
{
    /** Emails per referrer per hour from the admin "send link" action. */
    private const ADMIN_SENDS_PER_HOUR = 5;

    /** At most this many referrers share one email address in a public link request. */
    private const MAX_PUBLIC_SENDS = 5;

    public function __construct(private readonly TransactionalMailer $mailer) {}

    /**
     * Public "email me my link again": sends the dashboard link to every active
     * referrer with this address that still has a link. Says nothing to the caller.
     */
    public function emailDashboardLinks(string $email): int
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return 0;
        }

        $sent = 0;
        Referrer::query()
            ->active()
            ->where('email', $email)
            ->whereNotNull('dashboard_token_hash')
            ->orderBy('id')
            ->limit(self::MAX_PUBLIC_SENDS)
            ->get()
            ->each(function (Referrer $referrer) use (&$sent) {
                $sent += $this->mail($referrer) ? 1 : 0;
            });

        return $sent;
    }

    /** Administrator action: email the current dashboard link to the referrer. */
    public function sendDashboardLink(Referrer $referrer, AdminUser $admin): void
    {
        if (! $referrer->hasDashboardLink() || $referrer->email === null || ! $referrer->canAttribute()) {
            throw new RuntimeException('This referrer has no active dashboard link or email address.');
        }

        $key = 'referrer-link-mail:'.$referrer->id;
        if (RateLimiter::tooManyAttempts($key, self::ADMIN_SENDS_PER_HOUR)) {
            throw new RuntimeException('The link was already emailed several times in the last hour. Please wait before sending it again.');
        }
        RateLimiter::hit($key, 3600);

        $this->mail($referrer);
        Audit::log('referrer.dashboard_link_sent', $referrer, admin: $admin);
    }

    /** Issue a new dashboard link; the previous one stops working at once. */
    public function replaceDashboardLink(Referrer $referrer, AdminUser $admin): void
    {
        $hadLink = $referrer->hasDashboardLink();
        $referrer->issueDashboardToken();
        $referrer->save();

        Audit::log('referrer.dashboard_link_replaced', $referrer, meta: ['previous_link' => $hadLink ? 'disabled' : 'none'], admin: $admin);
    }

    /** Switch the dashboard link off (no link works until a new one is issued). */
    public function disableDashboardLink(Referrer $referrer, AdminUser $admin): void
    {
        $referrer->forceFill(['dashboard_token' => null, 'dashboard_token_hash' => null])->save();

        Audit::log('referrer.dashboard_link_disabled', $referrer, admin: $admin);
    }

    /** Record that an administrator opened the dashboard link (it is a credential). */
    public function dashboardLinkViewed(Referrer $referrer, AdminUser $admin): void
    {
        Audit::log('referrer.dashboard_link_viewed', $referrer, admin: $admin);
    }

    public function setActive(Referrer $referrer, bool $active, AdminUser $admin): void
    {
        if ($referrer->isErased() && $active) {
            throw new RuntimeException('An erased referrer cannot be reactivated. Create a new referrer instead.');
        }

        if ($referrer->is_active === $active) {
            return;
        }

        $referrer->forceFill(['is_active' => $active])->save();
        Audit::log($active ? 'referrer.activated' : 'referrer.deactivated', $referrer, ['is_active' => ! $active], ['is_active' => $active], admin: $admin);
    }

    /**
     * Erasure request: anonymise the referrer's name, email, phone, notes and
     * payment details, switch the referrer and the dashboard link off, and keep
     * the commission ledger, payout records and order attribution (accounting
     * records). Refused while a payout request is open (decide it first).
     */
    /** Erase a referrer's personal data (an administrator's request, or retention when $admin is null). */
    public function erase(Referrer $referrer, ?AdminUser $admin): void
    {
        if ($referrer->isErased()) {
            return;
        }

        if ($referrer->payouts()->where('status', ReferralPayout::STATUS_REQUESTED)->exists()) {
            throw new ReferralRefused('This referrer has an open payout request. Mark it as paid or reject it before erasing.');
        }

        DB::transaction(function () use ($referrer) {
            $referrer->forceFill([
                'name' => 'Erased referrer',
                'email' => null,
                'phone' => null,
                'notes' => null,
                'payment_details' => null,
                'pending_payment_details' => null,
                'pending_details_token_hash' => null,
                'pending_details_expires_at' => null,
                'is_active' => false,
                'dashboard_token' => null,
                'dashboard_token_hash' => null,
                'erased_at' => now(),
            ])->save();
        });

        Audit::log('referrer.erased', $referrer, meta: ['fields' => ['name', 'email', 'phone', 'notes', 'payment_details'], 'source' => $admin ? 'administrator' : 'retention'], admin: $admin);
    }

    private function mail(Referrer $referrer): bool
    {
        $link = $referrer->dashboardUrl();
        if ($link === null || $referrer->email === null) {
            return false;
        }

        $this->mailer->send(EmailTemplateKey::PartnerDashboardLink, $referrer->email, [
            'partner_name' => $referrer->name,
            'referral_code' => $referrer->code,
            'share_link' => $referrer->shareUrl(),
            'dashboard_link' => $link,
        ], meta: ['purpose' => 'referrer_dashboard_link']);

        return true;
    }
}
