<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(timestamps: false)]
#[Fillable(['email_id', 'provider', 'event_type', 'provider_message_id', 'payload', 'occurred_at', 'created_at'])]
class EmailEvent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_id');
    }
}
