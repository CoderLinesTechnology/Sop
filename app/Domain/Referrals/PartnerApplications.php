<?php

namespace App\Domain\Referrals;

use App\Domain\Email\TransactionalMailer;
use App\Domain\Notifications\AdminNotifier;
use App\Enums\EmailTemplateKey;
use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\Coupon;
use App\Models\PartnerApplication;
use App\Models\Referrer;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Become a partner" applications: submit → confirm email → admin approves
 * (creates the Referrer and emails the links) or rejects (optional reason).
 *
 * The public steps never reveal whether an email address has applied before;
 * a fresh confirmation email for the same address is sent at most once every
 * ten minutes (on top of the route's per-IP and per-email limits), so the
 * form cannot be used to flood someone's inbox.
 */
final class PartnerApplications
{
    public const CONFIRM_HOURS = 48;

    public const UNCONFIRMED_DAYS = 7;

    private const RESEND_COOLDOWN_MINUTES = 10;

    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly AdminNotifier $notifier,
    ) {}

    /** @param array<string, mixed> $data validated form data */
    public function submit(array $data): void
    {
        $email = Str::lower(trim((string) $data['email']));

        $recent = PartnerApplication::query()
            ->where('email', $email)
            ->where('status', PartnerApplication::STATUS_UNCONFIRMED)
            ->where('created_at', '>', now()->subMinutes(self::RESEND_COOLDOWN_MINUTES))
            ->exists();
        if ($recent) {
            return;
        }

        $token = Str::random(48);
        $application = new PartnerApplication([
            'full_name' => $this->text($data['full_name'], 120),
            'email' => $email,
            'phone' => $this->text($data['phone'], 40),
            'city' => $this->text($data['city'], 80),
            'country_code' => strtoupper((string) $data['country']),
            'promotion_channels' => array_values(array_intersect(array_keys(PartnerApplication::CHANNELS), (array) $data['channels'])),
            'promotion_details' => isset($data['channels_details']) ? $this->text($data['channels_details'], 500) : null,
            'audience_size' => (string) $data['audience_size'],
            'profile_links' => array_values(array_filter(array_map(fn ($link) => is_string($link) ? trim($link) : null, (array) ($data['links'] ?? [])))) ?: null,
            'motivation' => trim(strip_tags((string) $data['motivation'])),
            'preferred_code' => ReferralProgramme::normalize($data['preferred_code'] ?? null),
        ]);
        $application->forceFill([
            'status' => PartnerApplication::STATUS_UNCONFIRMED,
            'consented_at' => now(),
            'confirm_token_hash' => hash('sha256', $token),
            'confirm_expires_at' => now()->addHours(self::CONFIRM_HOURS),
        ])->save();

        $this->mailer->send(EmailTemplateKey::PartnerApplicationConfirm, $email, [
            'applicant_name' => $this->firstName($application->full_name),
            'confirm_link' => route('partners.apply.confirm', $token),
        ], meta: ['purpose' => 'partner_application']);
    }

    /** The unconfirmed application behind this link (unexpired), or null. */
    public function pendingFor(string $token): ?PartnerApplication
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            return null;
        }

        $hash = hash('sha256', $token);
        $application = PartnerApplication::query()->where('confirm_token_hash', $hash)->first();

        return $application
            && hash_equals((string) $application->confirm_token_hash, $hash)
            && $application->status === PartnerApplication::STATUS_UNCONFIRMED
            && $application->confirm_expires_at?->isFuture()
            ? $application
            : null;
    }

    /** Confirm the email behind this link (single use): the application now counts. */
    public function confirm(string $token): ?PartnerApplication
    {
        $application = $this->pendingFor($token);
        if (! $application) {
            return null;
        }

        $claimed = PartnerApplication::query()
            ->whereKey($application->getKey())
            ->where('status', PartnerApplication::STATUS_UNCONFIRMED)
            ->where('confirm_token_hash', hash('sha256', $token))
            ->update([
                'status' => PartnerApplication::STATUS_SUBMITTED,
                'confirmed_at' => now(),
                'confirm_token_hash' => null,
                'confirm_expires_at' => null,
                'updated_at' => now(),
            ]);
        if ($claimed !== 1) {
            return null;
        }

        $application->refresh();
        $this->safely(fn () => $this->mailer->send(EmailTemplateKey::PartnerApplicationReceived, $application->email, [
            'applicant_name' => $this->firstName($application->full_name),
        ], meta: ['purpose' => 'partner_application']));
        $this->safely(fn () => $this->notifier->notify(
            'partner_application',
            'New partner application',
            $application->full_name.' applied to become a referral partner. Review it in Pricing → Partner applications.',
            permissions: [Permission::ReferralsManage],
        ));

        return $application;
    }

    /** Approve: create the referrer with this code and rate, issue the dashboard link and email both links. */
    public function approve(PartnerApplication $application, string $code, int $rateBp, AdminUser $admin): Referrer
    {
        $code = ReferralProgramme::normalize($code) ?? throw new ReferralRefused('Enter a referral code (letters, numbers, dashes).');
        if (! self::codeIsFree($code)) {
            throw new ReferralRefused('This code is already used by a referrer or a coupon. Choose another one.');
        }
        $rateBp = max(0, min(Referrer::MAX_RATE_BP, $rateBp));

        $referrer = DB::transaction(function () use ($application, $code, $rateBp, $admin) {
            $locked = PartnerApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== PartnerApplication::STATUS_SUBMITTED) {
                throw new ReferralRefused('Only applications waiting for review can be approved.');
            }

            $referrer = Referrer::query()->create([
                'name' => $locked->full_name,
                'email' => $locked->email,
                'phone' => $locked->phone,
                'code' => $code,
                'commission_rate_bp' => $rateBp,
                'is_active' => true,
            ]);

            $locked->forceFill([
                'status' => PartnerApplication::STATUS_APPROVED,
                'referrer_id' => $referrer->id,
                'decided_at' => now(),
                'decided_by_admin_id' => $admin->id,
            ])->save();

            return $referrer;
        });

        Audit::log('partner_application.approved', 'PartnerApplication', after: ['application' => $application->public_id, 'referrer_code' => $referrer->code, 'commission_rate_bp' => $rateBp], admin: $admin);
        $this->safely(fn () => $this->mailer->send(EmailTemplateKey::PartnerApplicationApproved, (string) $referrer->email, [
            'partner_name' => $this->firstName($referrer->name),
            'referral_code' => $referrer->code,
            'commission_rate' => $referrer->formattedRate(),
            'share_link' => $referrer->shareUrl(),
            'dashboard_link' => $referrer->dashboardUrl(),
        ], meta: ['purpose' => 'partner_application']));

        return $referrer;
    }

    /** Reject a submitted application; the optional reason is sent to the applicant. */
    public function reject(PartnerApplication $application, ?string $reason, AdminUser $admin): void
    {
        $reason = $reason !== null ? trim(mb_substr(strip_tags($reason), 0, 1000)) : '';

        $rejected = PartnerApplication::query()
            ->whereKey($application->getKey())
            ->where('status', PartnerApplication::STATUS_SUBMITTED)
            ->update([
                'status' => PartnerApplication::STATUS_REJECTED,
                'rejection_reason' => $reason !== '' ? $reason : null,
                'decided_at' => now(),
                'decided_by_admin_id' => $admin->id,
                'updated_at' => now(),
            ]);
        if ($rejected !== 1) {
            throw new ReferralRefused('Only applications waiting for review can be rejected.');
        }

        $application->refresh();
        Audit::log('partner_application.rejected', 'PartnerApplication', after: ['application' => $application->public_id, 'reason' => $reason !== '' ? $reason : null], admin: $admin);
        $this->safely(fn () => $this->mailer->send(EmailTemplateKey::PartnerApplicationRejected, $application->email, [
            'applicant_name' => $this->firstName($application->full_name),
            'rejection_reason' => $reason !== '' ? [$reason] : [],
        ], meta: ['purpose' => 'partner_application']));
    }

    /** Internal notes (administrators only). */
    public function updateNotes(PartnerApplication $application, ?string $notes, AdminUser $admin): void
    {
        $application->forceFill(['admin_notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 5000) : null])->save();
        Audit::log('partner_application.notes_updated', 'PartnerApplication', meta: ['application' => $application->public_id], admin: $admin);
    }

    /** Erasure request: delete the application for good (an approved partner's referrer record is separate). */
    public function delete(PartnerApplication $application, AdminUser $admin): void
    {
        $meta = ['application' => $application->public_id, 'status' => $application->status];
        $application->delete();
        Audit::log('partner_application.deleted', 'PartnerApplication', meta: $meta, admin: $admin);
    }

    /** The preferred code when it is free, otherwise one built from the first name plus digits. */
    public function suggestCode(PartnerApplication $application): string
    {
        $preferred = ReferralProgramme::normalize($application->preferred_code);
        if ($preferred !== null && self::codeIsFree($preferred)) {
            return $preferred;
        }

        $stem = Str::upper(substr(preg_replace('/[^A-Za-z]/', '', Str::ascii(Str::before(trim($application->full_name), ' '))) ?: 'PARTNER', 0, 10));
        for ($i = 0; $i < 20; $i++) {
            $candidate = $stem.random_int(10, 9999);
            if (self::codeIsFree($candidate)) {
                return $candidate;
            }
        }

        return 'PARTNER'.Str::upper(Str::random(8));
    }

    /** Unique among referrers (archived included) and coupons (archived included). */
    public static function codeIsFree(string $code): bool
    {
        return ! Referrer::withTrashed()->where('code', $code)->exists()
            && ! Coupon::withTrashed()->where('code', $code)->exists();
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)) ?? ''), 0, $max);
    }

    private function firstName(string $name): string
    {
        return Str::before(trim($name), ' ') ?: $name;
    }

    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
