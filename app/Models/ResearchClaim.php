<?php

namespace App\Models;

use App\Enums\ClaimVerificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry of the Research Dossier: an external claim with its source and verification result. */
#[Fillable([
    'order_id', 'ai_job_id', 'research_source_id', 'claim_key', 'claim', 'category', 'supporting_quote',
    'confidence', 'relevance', 'relevance_score', 'verification_status', 'verification_method',
    'verification_notes', 'safe_to_use', 'conflict_group', 'used_in_document',
])]
class ResearchClaim extends Model
{
    protected function casts(): array
    {
        return [
            'verification_status' => ClaimVerificationStatus::class,
            'confidence' => 'decimal:2',
            'relevance_score' => 'decimal:2',
            'safe_to_use' => 'boolean',
            'used_in_document' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ResearchSource::class, 'research_source_id');
    }
}
