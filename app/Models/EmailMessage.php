<?php

namespace App\Models;

use App\Enums\EmailStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Log of one transactional email and its delivery state. */
#[Table(name: 'emails')]
#[Fillable([
    'uuid', 'order_id', 'template_key', 'to_email', 'subject', 'html_body', 'text_body', 'attachments', 'status',
    'mailer', 'provider_message_id', 'attempts', 'last_error', 'sent_at', 'delivered_at', 'failed_at', 'meta',
])]
class EmailMessage extends Model
{
    protected function casts(): array
    {
        return [
            'status' => EmailStatus::class,
            'attachments' => 'array',
            'meta' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (EmailMessage $email) => $email->uuid ??= (string) Str::uuid());
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class, 'email_id')->latest('occurred_at');
    }
}
