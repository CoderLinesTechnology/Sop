<?php

namespace App\Jobs;

use App\Domain\Delivery\DocumentDelivery;
use App\Enums\EmailStatus;
use App\Mail\TemplatedMail;
use App\Models\EmailMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers one logged email with controlled retries (exponential backoff).
 * Only after the provider accepts the message is it marked sent; a delivery
 * email that ultimately fails moves its order to DELIVERY_FAILED.
 */
class SendEmailMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public readonly int $emailId)
    {
        $this->onQueue('emails');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(): void
    {
        $email = EmailMessage::query()->find($this->emailId);
        if (! $email || $email->status->wasAccepted()) {
            return; // already sent (idempotent on retries)
        }

        $email->forceFill(['status' => EmailStatus::Sending, 'attempts' => $email->attempts + 1])->save();

        try {
            $sent = Mail::to($email->to_email)->send(new TemplatedMail($email));
        } catch (Throwable $e) {
            $email->forceFill(['status' => EmailStatus::Queued, 'last_error' => mb_substr($e->getMessage(), 0, 2000)])->save();
            throw $e;
        }

        $email->forceFill([
            'status' => EmailStatus::Sent,
            'sent_at' => now(),
            'last_error' => null,
            'provider_message_id' => $sent?->getSymfonySentMessage()?->getMessageId() ?? $sent?->getMessageId(),
        ])->save();

        if (data_get($email->meta, 'purpose') === 'delivery') {
            app(DocumentDelivery::class)->markDelivered($email);
        }
    }

    public function failed(Throwable $e): void
    {
        $email = EmailMessage::query()->find($this->emailId);
        if (! $email) {
            return;
        }

        $email->forceFill([
            'status' => EmailStatus::Failed,
            'failed_at' => now(),
            'last_error' => mb_substr($e->getMessage(), 0, 2000),
        ])->save();

        if (data_get($email->meta, 'purpose') === 'delivery') {
            app(DocumentDelivery::class)->markFailed($email, $e->getMessage());
        }
    }
}
