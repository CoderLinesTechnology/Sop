<?php

namespace App\Models;

use App\Enums\PipelineStage;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An administrator-defined AI workflow: which optional stages run, which model
 * and prompt key each stage uses, research depth, quality thresholds,
 * refinement rounds, retry and cost limits. A snapshot of the effective config
 * is stored on every AiJob so each generation is reproducible.
 */
#[Unguarded]
class AiWorkflow extends Model
{
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (AiWorkflow $workflow) {
            if ($workflow->isDirty('config')) {
                $workflow->version = (int) $workflow->getOriginal('version') + 1;
            }
        });
    }

    public function fallback(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fallback_workflow_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public static function default(): ?self
    {
        return static::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first();
    }

    /** The default configuration every workflow is merged over. */
    public static function defaultConfig(): array
    {
        $model = config('statementra.ai.default_model');
        $writer = config('statementra.ai.writing_model');

        $stages = [];
        foreach (PipelineStage::cases() as $stage) {
            $stages[$stage->value] = [
                'enabled' => true,
                'model' => in_array($stage, [PipelineStage::Writing, PipelineStage::Editorial], true) ? $writer : $model,
                'prompt_key' => $stage->value,
                // Deep reasoning only for the first draft; extraction is mechanical and the rest is well served by medium.
                'reasoning_effort' => match ($stage) {
                    PipelineStage::Writing => 'high',
                    PipelineStage::Ingestion => 'low',
                    default => 'medium',
                },
                'max_output_tokens' => 16000,
            ];
        }

        return [
            'stages' => $stages,
            'research' => [
                'max_search_calls' => 6,
                'official_first' => true,
                'allow_secondary_sources' => true,
                'search_context_size' => 'low',
                'verify_quotes' => true,
            ],
            'quality' => [
                'threshold' => 8.0,
                'min_category_score' => 6.5,
                'max_refinement_rounds' => 2,
            ],
            'limits' => [
                'max_cost_usd' => 4.00,
                'max_llm_calls' => 40,
                'max_search_calls' => 20,
                'max_duration_minutes' => 60,
                'max_stage_attempts' => 3,
                'max_length_revisions' => 3,
            ],
            'needs_information' => [
                'enabled' => true,
                'max_questions' => 3,
            ],
            'writing_samples' => [
                'enabled' => true,
                'max_samples' => 2,
            ],
            'on_budget_exceeded' => 'fallback',
        ];
    }

    /** Effective config: stored config deep-merged over the defaults. */
    public function effectiveConfig(): array
    {
        return array_replace_recursive(self::defaultConfig(), $this->config ?? []);
    }
}
