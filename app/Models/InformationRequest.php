<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A short follow-up question set sent to the customer when essential
 * information is missing (questions: [{key, question, why}]).
 */
#[Fillable(['order_id', 'source', 'questions', 'answers', 'status', 'requested_by_admin_id', 'requested_at', 'answered_at', 'reminder_sent_at', 'expires_at'])]
class InformationRequest extends Model
{
    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'answers' => 'array',
            'requested_at' => 'datetime',
            'answered_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
