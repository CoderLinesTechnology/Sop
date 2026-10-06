<?php

namespace App\Domain\Email\Tasks;

use App\Domain\Email\EmailSender;
use App\Enums\EmailStatus;
use App\Models\EmailMessage;

/** Heartbeat task: sends emails whose first attempt never ran or whose retry is due. */
class SendPendingEmails
{
    public function __construct(private readonly EmailSender $sender) {}

    public function __invoke(): void
    {
        EmailMessage::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', EmailStatus::Queued->value)
                    ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                    // A fresh email is sent by the request that created it; give that a minute.
                    ->where(fn ($q) => $q->where('attempts', '>', 0)->orWhere('created_at', '<=', now()->subMinute())))
                ->orWhere(fn ($q) => $q->where('status', EmailStatus::Sending->value)
                    ->where('updated_at', '<', now()->subMinutes(EmailSender::STUCK_AFTER_MINUTES))))
            ->orderBy('id')
            ->limit(20)
            ->pluck('id')
            ->each(fn (int $id) => $this->sender->send($id));
    }
}
