<?php

namespace App\Domain\Email;

use App\Domain\Delivery\DocumentDelivery;
use App\Enums\EmailStatus;
use App\Mail\TemplatedMail;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one logged email with controlled retries. Attempts are claimed with a
 * conditional update, so the request that queued the email and a heartbeat
 * retry can never send it twice. Only after the provider accepts the message
 * is it marked sent; a delivery email that ultimately fails moves its order
 * to DELIVERY_FAILED.
 */
class EmailSender
{
    public const MAX_ATTEMPTS = 5;

    /** Seconds to wait before the next attempt, keyed by attempts made so far. */
    private const BACKOFF = [1 => 30, 2 => 120, 3 => 600, 4 => 1800];

    /** An attempt still marked "sending" after this long died with its process. */
    public const STUCK_AFTER_MINUTES = 10;

    public function send(int $emailId): void
    {
        $claimed = EmailMessage::query()->whereKey($emailId)
            ->where(fn ($q) => $q->where('status', EmailStatus::Queued->value)
                ->orWhere(fn ($q) => $q->where('status', EmailStatus::Sending->value)
                    ->where('updated_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['status' => EmailStatus::Sending->value, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return; // already sent, being sent elsewhere, or waiting for its retry time
        }

        $email = EmailMessage::query()->findOrFail($emailId);

        try {
            $sent = Mail::to($email->to_email)->send(new TemplatedMail($email));
        } catch (Throwable $e) {
            $this->attemptFailed($email, $e);

            return;
        }

        $email->forceFill([
            'status' => EmailStatus::Sent,
            'sent_at' => now(),
            'next_attempt_at' => null,
            'last_error' => null,
            'provider_message_id' => $sent?->getSymfonySentMessage()?->getMessageId() ?? $sent?->getMessageId(),
        ])->save();

        if (data_get($email->meta, 'purpose') === 'delivery') {
            app(DocumentDelivery::class)->markDelivered($email);
        }
    }

    private function attemptFailed(EmailMessage $email, Throwable $e): void
    {
        report($e);
        $error = mb_substr($e->getMessage(), 0, 2000);

        if ($email->attempts >= self::MAX_ATTEMPTS) {
            $email->forceFill([
                'status' => EmailStatus::Failed,
                'failed_at' => now(),
                'next_attempt_at' => null,
                'last_error' => $error,
            ])->save();

            if (data_get($email->meta, 'purpose') === 'delivery') {
                app(DocumentDelivery::class)->markFailed($email, $e->getMessage());
            }

            return;
        }

        $email->forceFill([
            'status' => EmailStatus::Queued,
            'next_attempt_at' => now()->addSeconds(self::BACKOFF[$email->attempts] ?? 1800),
            'last_error' => $error,
        ])->save();
    }
}
