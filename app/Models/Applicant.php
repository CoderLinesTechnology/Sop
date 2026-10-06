<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The structured Applicant Profile extracted from the customer's answers and
 * documents. Every fact carries its source; nothing is inferred or invented.
 */
#[Fillable(['order_id', 'full_name', 'profile', 'missing_information', 'source_files', 'model'])]
class Applicant extends Model
{
    protected function casts(): array
    {
        return [
            'profile' => 'array',
            'missing_information' => 'array',
            'source_files' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
