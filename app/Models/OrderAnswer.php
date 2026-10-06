<?php

namespace App\Models;

use App\Enums\FieldSection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's answer to one form field. Label, type and section are
 * snapshotted so later edits to the service form never rewrite history.
 */
#[Fillable(['order_id', 'field_key', 'label', 'type', 'section', 'value'])]
class OrderAnswer extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'section' => FieldSection::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Value rendered as plain text (lists joined, booleans as Yes/No). */
    public function displayValue(): string
    {
        $value = $this->value;

        return match (true) {
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) => implode(', ', array_map('strval', $value)),
            default => (string) $value,
        };
    }
}
