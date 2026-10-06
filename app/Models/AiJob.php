<?php

namespace App\Models;

use App\Enums\AiJobStatus;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One run of the AI pipeline for an order (or a revision). dedupe_key is
 * unique, so a duplicated payment webhook can never create a second job.
 */
#[Fillable([
    'uuid', 'order_id', 'revision_id', 'kind', 'dedupe_key', 'status', 'current_stage', 'ai_workflow_id',
    'workflow_snapshot', 'prompt_versions', 'provider', 'started_at', 'heartbeat_at', 'finished_at',
    'total_input_tokens', 'total_cached_tokens', 'total_output_tokens', 'total_reasoning_tokens', 'llm_calls',
    'search_calls', 'refinement_rounds', 'total_cost_usd', 'failure_count', 'last_error_code',
    'last_error_message', 'used_fallback',
])]
class AiJob extends Model
{
    public const KIND_ORDER = 'order_pipeline';
    public const KIND_REVISION = 'revision';
    public const KIND_REGENERATION = 'regeneration';

    protected $attributes = [
        'total_input_tokens' => 0,
        'total_cached_tokens' => 0,
        'total_output_tokens' => 0,
        'total_reasoning_tokens' => 0,
        'llm_calls' => 0,
        'search_calls' => 0,
        'refinement_rounds' => 0,
        'total_cost_usd' => 0,
        'failure_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => AiJobStatus::class,
            'current_stage' => PipelineStage::class,
            'workflow_snapshot' => 'array',
            'prompt_versions' => 'array',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'finished_at' => 'datetime',
            'next_run_at' => 'datetime',
            'leased_until' => 'datetime',
            'total_cost_usd' => 'decimal:6',
            'used_fallback' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (AiJob $job) => $job->uuid ??= (string) Str::uuid());
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(Revision::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AiWorkflow::class, 'ai_workflow_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AiJobStep::class)->orderBy('sequence')->orderBy('attempt');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(AiUsage::class);
    }

    public function qualityReviews(): HasMany
    {
        return $this->hasMany(QualityReview::class)->orderBy('round');
    }

    /** The latest completed output of a stage, if any. */
    public function stageOutput(PipelineStage $stage): ?array
    {
        $step = $this->steps()
            ->where('stage', $stage->value)
            ->where('status', StepStatus::Completed->value)
            ->latest('id')
            ->first();

        return $step?->output;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->workflow_snapshot, $key, $default);
    }

    public function isRevision(): bool
    {
        return $this->kind === self::KIND_REVISION;
    }

    /** A worker currently holds this job (request-driven runtime lease). */
    public function isLeased(): bool
    {
        return $this->leased_until !== null && $this->leased_until->isFuture();
    }

    /** Runnable, due now and not leased: the next stage may start. */
    public function isDue(): bool
    {
        return $this->status instanceof AiJobStatus
            && $this->status->isRunnable()
            && ($this->next_run_at === null || ! $this->next_run_at->isFuture())
            && ! $this->isLeased();
    }

    /** Jobs whose next stage may start now (runnable, due, not leased). */
    public function scopeDue(Builder $query): void
    {
        $now = now();

        $query->whereIn('status', [AiJobStatus::Queued->value, AiJobStatus::Running->value])
            ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('leased_until')->orWhere('leased_until', '<', $now));
    }

    public function durationMinutes(): ?float
    {
        if (! $this->started_at) {
            return null;
        }

        return round($this->started_at->diffInSeconds($this->finished_at ?? now()) / 60, 1);
    }
}
