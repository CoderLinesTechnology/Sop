<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Usage and estimated cost of a single model call. */
#[Table(timestamps: false)]
#[Fillable([
    'ai_job_id', 'ai_job_step_id', 'order_id', 'service_id', 'provider', 'model', 'stage', 'prompt_version_id',
    'response_id', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'search_calls',
    'duration_ms', 'estimated_cost_usd', 'status', 'error_type', 'created_at',
])]
class AiUsage extends Model
{
    protected function casts(): array
    {
        return [
            'estimated_cost_usd' => 'decimal:6',
            'created_at' => 'datetime',
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
