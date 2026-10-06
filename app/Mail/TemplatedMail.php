<?php

namespace App\Mail;

use App\Domain\Files\FileVault;
use App\Models\EmailMessage;
use App\Support\Settings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * A pre-rendered transactional email from the emails log. Attachments are
 * decrypted from the private vault in memory at send time; they are never
 * placed in public storage.
 */
class TemplatedMail extends Mailable
{
    public function __construct(public readonly EmailMessage $email) {}

    public function envelope(): Envelope
    {
        $fromAddress = Settings::get('email.from_address') ?: config('mail.from.address');
        $fromName = Settings::get('email.from_name') ?: config('mail.from.name');
        $replyTo = Settings::get('email.reply_to') ?: Settings::supportEmail();

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            replyTo: $replyTo ? [new Address($replyTo)] : [],
            subject: $this->email->subject,
            tags: array_filter([$this->email->template_key]),
            metadata: ['email_uuid' => $this->email->uuid],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'X-Statementra-Email' => $this->email->uuid,
            'X-Auto-Response-Suppress' => 'OOF, AutoReply',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            htmlString: (string) $this->email->html_body,
            text: 'emails.plain',
            with: ['body' => (string) $this->email->text_body],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $vault = app(FileVault::class);

        return collect($this->email->attachments ?? [])
            ->map(fn (array $file) => Attachment::fromData(
                fn () => $vault->get($file['path'], $file['disk'] ?? null, (bool) ($file['encrypted'] ?? true)),
                $file['filename'],
            )->withMime($file['mime']))
            ->values()
            ->all();
    }
}
