<?php

use App\Domain\Email\EmailSender;
use App\Domain\Email\Tasks\SendPendingEmails;
use App\Enums\EmailStatus;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\Mail;

function queuedEmail(array $attributes = []): EmailMessage
{
    return EmailMessage::query()->create(array_merge([
        'template_key' => 'payment_received',
        'to_email' => 'ama@example.com',
        'subject' => 'Payment received',
        'html_body' => '<p>Thanks</p>',
        'text_body' => 'Thanks',
        'status' => EmailStatus::Queued,
        'mailer' => 'array',
    ], $attributes));
}

it('sends a queued email once', function () {
    $email = queuedEmail();
    $sender = app(EmailSender::class);

    $sender->send($email->id);
    $sender->send($email->id);

    expect($email->refresh()->status)->toBe(EmailStatus::Sent)
        ->and($email->attempts)->toBe(1)
        ->and($email->sent_at)->not->toBeNull()
        ->and(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('retries with backoff and gives up after the last attempt', function () {
    $email = queuedEmail();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP timeout'));
    $sender = app(EmailSender::class);

    $sender->send($email->id);
    $email->refresh();
    expect($email->status)->toBe(EmailStatus::Queued)
        ->and($email->attempts)->toBe(1)
        ->and($email->next_attempt_at->isFuture())->toBeTrue()
        ->and($email->last_error)->toContain('SMTP timeout');

    $sender->send($email->id); // too early: waits for its retry time
    expect($email->refresh()->attempts)->toBe(1);

    for ($i = 2; $i <= EmailSender::MAX_ATTEMPTS; $i++) {
        $this->travelTo($email->refresh()->next_attempt_at->addSecond());
        app()->call([app(SendPendingEmails::class), '__invoke']);
    }

    expect($email->refresh()->status)->toBe(EmailStatus::Failed)
        ->and($email->attempts)->toBe(EmailSender::MAX_ATTEMPTS)
        ->and($email->failed_at)->not->toBeNull();
});

it('re-sends an email whose sending process died', function () {
    $email = queuedEmail(['status' => EmailStatus::Sending, 'attempts' => 1]);
    $email->forceFill(['updated_at' => now()->subMinutes(EmailSender::STUCK_AFTER_MINUTES + 1)])->saveQuietly();

    app()->call([app(SendPendingEmails::class), '__invoke']);

    expect($email->refresh()->status)->toBe(EmailStatus::Sent)->and($email->attempts)->toBe(2);
});
