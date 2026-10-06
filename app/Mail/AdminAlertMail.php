<?php

namespace App\Mail;

use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Internal operational alert sent to the admin notification addresses. */
class AdminAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $alertTitle,
        public readonly string $alertBody,
        public readonly ?string $actionUrl = null,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.Settings::siteName().' admin] '.$this->alertTitle);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.admin-alert');
    }
}
