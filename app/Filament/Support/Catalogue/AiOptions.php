<?php

namespace App\Filament\Support\Catalogue;

use App\Domain\Ai\Prompts\DefaultPrompts;
use App\Enums\PipelineStage;
use App\Models\AiModelPrice;
use App\Models\AiWorkflow;
use App\Models\PromptVersion;

/** Option lists shared by the AI workflow, prompt and control-centre screens. */
final class AiOptions
{
    public const REASONING_EFFORTS = [
        'minimal' => 'Minimal',
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
    ];

    public const SEARCH_CONTEXT_SIZES = [
        'low' => 'Low (cheapest)',
        'medium' => 'Medium',
        'high' => 'High (most thorough)',
    ];

    public const BUDGET_EXCEEDED = [
        'fallback' => 'Continue with the fallback workflow',
        'manual_review' => 'Stop and send the order to manual review',
    ];

    public const PROMPT_KEY_PATTERN = '/^[a-z][a-z0-9_.-]*$/';

    /** @return list<string> models with a price, plus the configured defaults */
    public static function models(): array
    {
        $models = AiModelPrice::query()->orderBy('model')->pluck('model')->all();

        return array_values(array_unique(array_filter([
            ...$models,
            (string) config('statementra.ai.default_model'),
            (string) config('statementra.ai.writing_model'),
        ])));
    }

    /**
     * Built-in prompts shipped with the AI pipeline (used when a key has no active version).
     *
     * @return array<string, array{system_prompt:string, user_template:string}>
     */
    public static function builtInPrompts(): array
    {
        return class_exists(DefaultPrompts::class) ? DefaultPrompts::all() : [];
    }

    /** @return array{system_prompt:string, user_template:string}|null */
    public static function builtInPrompt(string $key): ?array
    {
        return self::builtInPrompts()[$key] ?? null;
    }

    /** @return list<string> prompt keys in use (versions, built-in prompts, stages and workflows) */
    public static function promptKeys(): array
    {
        $keys = [
            ...PromptVersion::query()->distinct()->orderBy('prompt_key')->pluck('prompt_key')->all(),
            ...array_keys(self::builtInPrompts()),
        ];

        foreach (PipelineStage::cases() as $stage) {
            if ($stage->usesModel()) {
                $keys[] = $stage->value;
            }
        }

        foreach (AiWorkflow::query()->get(['config']) as $workflow) {
            foreach ((array) data_get($workflow->config, 'stages', []) as $stage) {
                if (is_array($stage) && filled($stage['prompt_key'] ?? null)) {
                    $keys[] = (string) $stage['prompt_key'];
                }
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }
}
