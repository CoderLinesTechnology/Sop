<?php

namespace App\Models;

use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A checkpoint: one attempt at one pipeline stage, with its output and cost. */
#[Fillable([
    'ai_job_id', 'stage', 'sequence', 'attempt', 'status', 'model', 'prompt_version_id', 'input_summary', 'output',
    'error_code', 'error_message', 'started_at', 'finished_at', 'duration_ms', 'input_tokens', 'output_tokens',
    'cost_usd',
])]
class AiJobStep extends Model
{
    protected function casts(): array
    {
        return [
            'stage' => PipelineStage::class,
            'status' => StepStatus::class,
            'input_summary' => 'array',
            'output' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cost_usd' => 'decimal:6',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'ai_job_id');
    }

    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class);
    }
}
