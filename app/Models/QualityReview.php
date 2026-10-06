<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Internal quality scores for one review round of a generated document. */
#[Fillable(['ai_job_id', 'order_id', 'round', 'scores', 'overall_score', 'threshold', 'passed', 'answers_prompt', 'issues', 'instructions', 'reviewer', 'model'])]
class QualityReview extends Model
{
    public const CATEGORIES = [
        'personalization' => 'Personalization',
        'specificity' => 'Specificity',
        'relevance' => 'Relevance',
        'structure' => 'Structure',
        'grammar' => 'Grammar',
        'naturalness' => 'Naturalness',
        'programme_fit' => 'Programme fit',
        'prompt_adherence' => 'Prompt adherence',
        'factual_accuracy' => 'Factual accuracy',
        'narrative_strength' => 'Narrative strength',
    ];

    protected function casts(): array
    {
        return [
            'scores' => 'array',
            'issues' => 'array',
            'overall_score' => 'decimal:2',
            'threshold' => 'decimal:2',
            'passed' => 'boolean',
            'answers_prompt' => 'boolean',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'ai_job_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
