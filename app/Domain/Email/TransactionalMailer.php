<?php

namespace App\Domain\Email;

use App\Enums\EmailStatus;
use App\Enums\EmailTemplateKey;
use App\Models\EmailMessage;
use App\Models\Order;
use App\Support\Runtime\AfterResponse;

/**
 * Sends a templated transactional email: renders it, records it in the
 * emails log (so admins can see exactly what was sent) and queues delivery
 * with retries.
 *
 * Attachments are references to encrypted files in the private vault:
 * [['disk' => ..., 'path' => ..., 'encrypted' => true, 'filename' => ..., 'mime' => ...]].
 */
class TransactionalMailer
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    /**
     * @param  array<string, mixed>  $variables
     * @param  list<array{disk:string,path:string,encrypted:bool,filename:string,mime:string,size?:int}>  $attachments
     * @param  array<string, mixed>  $meta  e.g. ['purpose' => 'delivery', 'document_version_id' => 12]
     * @param  bool  $afterCommit  kept for compatibility: sending always waits for the surrounding transaction to commit
     */
    public function send(
        EmailTemplateKey $key,
        string $to,
        array $variables = [],
        ?Order $order = null,
        array $attachments = [],
        array $meta = [],
        bool $afterCommit = true,
    ): ?EmailMessage {
        $template = $this->renderer->template($key);
        if (! $template->is_active && ! $key->isEssential()) {
            return null;
        }

        $rendered = $this->renderer->render($template, $variables);

        $email = EmailMessage::query()->create([
            'order_id' => $order?->id,
            'template_key' => $key->value,
            'to_email' => $to,
            'subject' => $rendered['subject'],
            'html_body' => $rendered['html'],
            'text_body' => $rendered['text'],
            'attachments' => $attachments ?: null,
            'status' => EmailStatus::Queued,
            'mailer' => config('mail.default'),
            'meta' => $meta ?: null,
        ]);

        // Sent right after the response (after the surrounding transaction commits);
        // the heartbeat retries anything that does not go out.
        $this->sendAfterResponse($email);

        return $email;
    }

    /** Re-queue a previously logged email (admin "resend"). */
    public function resend(EmailMessage $original): EmailMessage
    {
        $copy = $original->replicate(['uuid', 'status', 'provider_message_id', 'attempts', 'last_error', 'sent_at', 'delivered_at', 'failed_at']);
        $copy->status = EmailStatus::Queued;
        $copy->meta = array_merge($original->meta ?? [], ['resend_of' => $original->uuid]);
        $copy->save();

        $this->sendAfterResponse($copy);

        return $copy;
    }

    private function sendAfterResponse(EmailMessage $email): void
    {
        $id = $email->id;
        AfterResponse::run('email:'.$id, fn () => app(EmailSender::class)->send($id), timeLimitSeconds: 120);
    }
}
