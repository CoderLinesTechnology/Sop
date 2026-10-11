<?php

namespace App\Domain\Referrals;

use App\Domain\Email\TransactionalMailer;
use App\Domain\Notifications\AdminNotifier;
use App\Enums\EmailTemplateKey;
use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\ReferralCommission;
use App\Models\ReferralPayout;
use App\Models\Referrer;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Payout requests, the only way commission is paid out.
 *
 *  - request(): the referrer asks for their whole available balance (site
 *    currency). Under a lock on the referrer row, every available ledger entry
 *    is linked to the new request; their sum is the amount, which must be > 0
 *    and ≥ referrals.minimum_payout. Confirmed payment details are required
 *    and snapshotted. One open request per referrer (also a unique index), and
 *    a repeated click returns the open request instead of a second one.
 *  - markPaid(): an administrator records the transfer reference; the linked
 *    entries are stamped paid (paid_at, payout_reference, paid_by_admin_id).
 *  - reject() / cancel(): the entries are unlinked and become available again.
 *
 * Emails and admin notifications are sent after the transaction commits.
 */
final class ReferralPayouts
{
    public function __construct(
        private readonly ReferralBalances $balances,
        private readonly TransactionalMailer $mailer,
        private readonly AdminNotifier $notifier,
    ) {}

    /**
     * Whether the referrer can request a payout now, and why not.
     *
     * @return array{allowed:bool, reason:?string, available:int, minimum:int, currency:string}
     */
    public function eligibility(Referrer $referrer): array
    {
        $currency = Settings::currency();
        $minimum = ReferralProgramme::minimumPayout();
        $available = $this->balances->inCurrency($referrer, $currency)['available'];
        $open = $referrer->payouts()->where('status', ReferralPayout::STATUS_REQUESTED)->exists();

        $reason = match (true) {
            $open => 'Your payout request is being processed.',
            $available < $minimum || $available <= 0 => 'You can request a payout once your available balance reaches '
                .Money::format($minimum, $currency, true).' ('.Money::format($minimum - max(0, $available), $currency, true).' to go).',
            ! $referrer->hasPaymentDetails() => 'Add your payment details to request a payout.',
            default => null,
        };

        return ['allowed' => $reason === null, 'reason' => $reason, 'available' => $available, 'minimum' => $minimum, 'currency' => $currency];
    }

    /** The referrer requests their whole available balance. Returns the (new or already open) request. */
    public function request(Referrer $referrer): ReferralPayout
    {
        $currency = Settings::currency();
        $minimum = ReferralProgramme::minimumPayout();

        try {
            [$payout, $created] = DB::transaction(function () use ($referrer, $currency, $minimum) {
                /** @var Referrer $locked */
                $locked = Referrer::withTrashed()->whereKey($referrer->getKey())->lockForUpdate()->firstOrFail();
                if (! $locked->canAttribute()) {
                    throw new ReferralRefused('This partner account is not active.');
                }

                $open = ReferralPayout::query()->where('referrer_id', $locked->id)->where('status', ReferralPayout::STATUS_REQUESTED)->first();
                if ($open) {
                    return [$open, false];
                }

                if (! $locked->hasPaymentDetails()) {
                    throw new ReferralRefused('Add your payment details to request a payout.');
                }

                $entries = $this->balances->availableEntriesForUpdate($locked, $currency);
                $amount = (int) $entries->sum('amount');
                if ($amount <= 0 || $amount < $minimum) {
                    throw new ReferralRefused('You can request a payout once your available balance reaches '.Money::format($minimum, $currency, true).'.');
                }

                $payout = new ReferralPayout;
                $payout->forceFill([
                    'referrer_id' => $locked->id,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => ReferralPayout::STATUS_REQUESTED,
                    'payment_details' => $locked->payment_details,
                    'requested_at' => now(),
                ])->save();

                $linked = ReferralCommission::query()
                    ->whereKey($entries->modelKeys())
                    ->whereNull('payout_id')
                    ->whereNull('paid_at')
                    ->update(['payout_id' => $payout->id, 'updated_at' => now()]);
                if ($linked !== $entries->count()) {
                    throw new ReferralRefused('Your balance changed while the request was being made. Please try again.');
                }

                return [$payout, true];
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests raced past the lock (should not happen): the open one wins.
            return ReferralPayout::query()->where('referrer_id', $referrer->id)->where('status', ReferralPayout::STATUS_REQUESTED)->firstOrFail();
        }

        if ($created) {
            Audit::log('referrer.payout_requested', $referrer, after: ['payout' => $payout->public_id, 'amount' => Money::format($payout->amount, $payout->currency, true)]);
            $this->notify($referrer, EmailTemplateKey::PartnerPayoutRequested, $payout);
            $this->safely(fn () => $this->notifier->notify(
                'referral_payout_requested',
                'Payout requested · '.$referrer->code,
                Money::format($payout->amount, $payout->currency, true).' requested by '.$referrer->name.' ('.$referrer->code.'). Pay it, then record the reference in Pricing → Payout requests.',
                permissions: [Permission::ReferralsPayouts],
            ));
        }

        return $payout;
    }

    /** The referrer withdraws their open request; the amount becomes available again. */
    public function cancel(Referrer $referrer, ReferralPayout $payout): void
    {
        if ($payout->referrer_id !== $referrer->id) {
            throw new ReferralRefused('This payout request was not found.');
        }

        $this->close($payout, ReferralPayout::STATUS_CANCELLED, null, null);
        Audit::log('referrer.payout_cancelled', $referrer, after: ['payout' => $payout->public_id]);
    }

    /** An administrator records the transfer: the request and its ledger entries are paid. */
    public function markPaid(ReferralPayout $payout, string $reference, AdminUser $admin): ReferralPayout
    {
        $reference = trim(mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $reference) ?? '', 0, 100));
        if ($reference === '') {
            throw new ReferralRefused('Enter the transfer or mobile money reference.');
        }

        DB::transaction(function () use ($payout, $reference, $admin) {
            $locked = ReferralPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new ReferralRefused('Only a requested payout can be marked as paid (this one is '.strtolower($locked->statusLabel()).').');
            }

            $linkedTotal = (int) ReferralCommission::query()->where('payout_id', $locked->id)->whereNull('paid_at')->sum('amount');
            if ($linkedTotal !== $locked->amount) {
                Log::error('Payout amount does not match its ledger entries', ['payout' => $locked->public_id, 'amount' => $locked->amount, 'entries' => $linkedTotal]);
                throw new ReferralRefused('This request no longer matches the ledger. Reject it so the referrer can request again.');
            }

            ReferralCommission::query()
                ->where('payout_id', $locked->id)
                ->whereNull('paid_at')
                ->update(['paid_at' => now(), 'payout_reference' => $reference, 'paid_by_admin_id' => $admin->id, 'updated_at' => now()]);

            $locked->forceFill([
                'status' => ReferralPayout::STATUS_PAID,
                'payout_reference' => $reference,
                'decided_at' => now(),
                'decided_by_admin_id' => $admin->id,
            ])->save();
        });

        $payout->refresh();
        $referrer = $payout->referrer;
        Audit::log('referral_payout.paid', $payout, after: [
            'referrer' => $referrer?->code,
            'amount' => Money::format($payout->amount, $payout->currency, true),
            'payout_reference' => $reference,
        ], admin: $admin);
        if ($referrer) {
            $this->notify($referrer, EmailTemplateKey::PartnerPayoutPaid, $payout, ['payout_reference' => $reference]);
        }

        return $payout;
    }

    /** An administrator rejects the request (reason sent to the referrer); the amount becomes available again. */
    public function reject(ReferralPayout $payout, string $reason, AdminUser $admin): ReferralPayout
    {
        $reason = trim(mb_substr(strip_tags($reason), 0, 1000));
        if ($reason === '') {
            throw new ReferralRefused('Enter the reason the referrer will see.');
        }

        $this->close($payout, ReferralPayout::STATUS_REJECTED, $reason, $admin);

        $payout->refresh();
        Audit::log('referral_payout.rejected', $payout, after: ['referrer' => $payout->referrer?->code, 'reason' => $reason], admin: $admin);
        if ($payout->referrer) {
            $this->notify($payout->referrer, EmailTemplateKey::PartnerPayoutRejected, $payout, ['rejection_reason' => [$reason]]);
        }

        return $payout;
    }

    private function close(ReferralPayout $payout, string $status, ?string $reason, ?AdminUser $admin): void
    {
        DB::transaction(function () use ($payout, $status, $reason, $admin) {
            $locked = ReferralPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new ReferralRefused('This payout request has already been '.strtolower($locked->statusLabel()).'.');
            }

            ReferralCommission::query()
                ->where('payout_id', $locked->id)
                ->whereNull('paid_at')
                ->update(['payout_id' => null, 'updated_at' => now()]);

            $locked->forceFill([
                'status' => $status,
                'rejection_reason' => $reason,
                'decided_at' => now(),
                'decided_by_admin_id' => $admin?->id,
            ])->save();
        });
    }

    /** @param array<string, mixed> $extra */
    private function notify(Referrer $referrer, EmailTemplateKey $key, ReferralPayout $payout, array $extra = []): void
    {
        if ($referrer->email === null) {
            return;
        }

        $this->safely(fn () => $this->mailer->send($key, $referrer->email, $extra + [
            'partner_name' => $referrer->name,
            'payout_amount' => Money::format($payout->amount, $payout->currency, true),
            'payment_method' => PaymentDetails::mask($payout->payment_details) ?? 'your payment details',
            'dashboard_link' => $referrer->dashboardUrl() ?? route('partner.request'),
        ], meta: ['purpose' => 'referral_payout']));
    }

    /** Emails and notifications follow a committed change; they must never undo or hide it. */
    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
