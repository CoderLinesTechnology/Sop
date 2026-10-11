<?php

namespace App\Domain\Referrals;

use App\Domain\Email\TransactionalMailer;
use App\Enums\EmailTemplateKey;
use App\Models\Referrer;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Adding or changing a referrer's payment details.
 *
 * The dashboard link has no password, so whoever holds it could try to send
 * the money elsewhere. A change (the first one too) is therefore only stored
 * as pending, encrypted, and takes effect when the referrer confirms it from
 * the link emailed to their address: a single-use 48-character token (only
 * its hash is stored) valid for 48 hours. Opening the link shows the change;
 * only pressing the button applies it, so mail scanners that open links
 * cannot confirm it. Confirmed changes stamp payment_details_changed_at, which
 * the payout screens use to warn administrators about recent changes.
 */
final class ReferrerPaymentDetails
{
    private const VALID_HOURS = 48;

    private const CHANGES_PER_HOUR = 3;

    public function __construct(private readonly TransactionalMailer $mailer) {}

    /**
     * @param  array<string, mixed>  $input  validated with PaymentDetails::rules()
     *
     * @throws ReferralRefused when the referrer cannot receive the confirmation email or asked too often
     */
    public function requestChange(Referrer $referrer, array $input): void
    {
        if ($referrer->email === null || ! $referrer->canAttribute()) {
            throw new ReferralRefused('Payment details cannot be changed for this account. Please contact support.');
        }

        $key = 'referrer-details-change:'.$referrer->id;
        if (RateLimiter::tooManyAttempts($key, self::CHANGES_PER_HOUR)) {
            throw new ReferralRefused('You have asked to change your payment details several times in the last hour. Please use the confirmation email we already sent, or try again later.');
        }
        RateLimiter::hit($key, 3600);

        $details = PaymentDetails::fromInput($input);
        $token = Str::random(48);

        $referrer->forceFill([
            'pending_payment_details' => $details,
            'pending_details_token_hash' => hash('sha256', $token),
            'pending_details_expires_at' => now()->addHours(self::VALID_HOURS),
        ])->save();

        Audit::log('referrer.payment_details_change_requested', $referrer, meta: ['method' => $details['method']]);

        $this->mailer->send(EmailTemplateKey::PartnerPaymentDetailsConfirm, $referrer->email, [
            'partner_name' => $referrer->name,
            'payment_method' => PaymentDetails::mask($details),
            'confirm_link' => route('partner.details.confirm', $token),
        ], meta: ['purpose' => 'referrer_payment_details']);
    }

    /** The referrer whose pending change this token confirms (unexpired, active), or null. */
    public function pendingFor(string $token): ?Referrer
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            return null;
        }

        $hash = hash('sha256', $token);
        $referrer = Referrer::query()->where('pending_details_token_hash', $hash)->first();

        return $referrer
            && hash_equals((string) $referrer->pending_details_token_hash, $hash)
            && $referrer->pending_details_expires_at?->isFuture()
            && $referrer->canAttribute()
            && is_array($referrer->pending_payment_details)
            ? $referrer
            : null;
    }

    /** Apply the pending change behind this token (single use). Returns the referrer, or null when the link is not valid. */
    public function confirm(string $token): ?Referrer
    {
        $referrer = $this->pendingFor($token);
        if (! $referrer) {
            return null;
        }

        $applied = DB::transaction(function () use ($referrer, $token) {
            $locked = Referrer::query()->whereKey($referrer->getKey())->lockForUpdate()->first();
            if (! $locked || ! hash_equals((string) $locked->pending_details_token_hash, hash('sha256', $token)) || ! is_array($locked->pending_payment_details)) {
                return false;
            }

            $locked->forceFill([
                'payment_details' => $locked->pending_payment_details,
                'payment_details_changed_at' => now(),
                'pending_payment_details' => null,
                'pending_details_token_hash' => null,
                'pending_details_expires_at' => null,
            ])->save();

            return true;
        });

        if (! $applied) {
            return null;
        }

        $referrer->refresh();
        Audit::log('referrer.payment_details_changed', $referrer, meta: ['method' => $referrer->payment_details['method'] ?? null]);

        return $referrer;
    }
}
