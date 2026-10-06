<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Requirement verification log: the resolved requirements used to write and
 * format an order's document, the sources checked and any conflicts.
 * Internal only; never included in the customer's document.
 */
#[Fillable(['order_id', 'ai_job_id', 'resolved', 'sources', 'conflicts', 'applied_rule_ids', 'document_template_id', 'language_variant', 'max_words', 'max_characters', 'max_pages', 'last_verified_at'])]
class OrderRequirement extends Model
{
    protected function casts(): array
    {
        return [
            'resolved' => 'array',
            'sources' => 'array',
            'conflicts' => 'array',
            'applied_rule_ids' => 'array',
            'last_verified_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }
}
