<?php

namespace Database\Seeders;

use App\Domain\Ai\Prompts\DefaultPrompts;
use App\Models\AiModelPrice;
use App\Models\AiWorkflow;
use App\Models\PromptVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AI pipeline configuration: the default "Standard" workflow (with an
 * "Economy fallback" workflow used once when a job exceeds its budget),
 * version-1 prompts for every prompt key (active) and model prices.
 *
 * Idempotent and non-destructive: existing workflows, prompt keys and prices
 * (which administrators edit and version in the panel) are never touched.
 */
class AiConfigurationSeeder extends Seeder
{
    /**
     * USD per million tokens and per web-search call. Estimates for the
     * models this installation is configured with — verify against current
     * OpenAI pricing and adjust in the admin panel.
     */
    private const PRICES = [
        'gpt-6-astra' => [5.00, 0.50, 40.00, 0.01, 'Flagship model (writing and editorial). Estimated price; verify against current OpenAI pricing.'],
        'gpt-6.1-sol' => [2.00, 0.20, 16.00, 0.01, 'Default model (analysis, research, review). Estimated price; verify against current OpenAI pricing.'],
        'gpt-6-luna' => [0.40, 0.04, 3.20, 0.01, 'Economy model (fallback workflow). Estimated price; verify against current OpenAI pricing.'],
        'gpt-5.5' => [1.25, 0.125, 10.00, 0.01, 'Previous generation. Estimated price; verify against current OpenAI pricing.'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $this->prices();
            $economy = $this->economyWorkflow();
            $this->standardWorkflow($economy);
            $this->prompts();
        });
    }

    private function prices(): void
    {
        foreach (self::PRICES as $model => [$input, $cached, $output, $search, $notes]) {
            AiModelPrice::query()->firstOrCreate(['model' => $model], [
                'input_per_million' => $input,
                'cached_input_per_million' => $cached,
                'output_per_million' => $output,
                'web_search_per_call' => $search,
                'notes' => $notes,
                'is_active' => true,
            ]);
        }
    }

    private function economyWorkflow(): AiWorkflow
    {
        $config = AiWorkflow::defaultConfig();
        $writer = (string) config('statementra.ai.default_model');

        foreach ($config['stages'] as $stage => $settings) {
            $isWriting = in_array($stage, ['writing', 'editorial', 'limits'], true);
            $config['stages'][$stage]['model'] = $isWriting ? $writer : 'gpt-6-luna';
            $config['stages'][$stage]['reasoning_effort'] = in_array($stage, ['writing', 'strategy', 'quality_review'], true) ? 'medium' : 'low';
            $config['stages'][$stage]['max_output_tokens'] = 12000;
        }

        $config['research'] = array_replace($config['research'], [
            'max_search_calls' => 6,
            'search_context_size' => 'low',
        ]);
        $config['quality']['max_refinement_rounds'] = 1;
        $config['limits'] = array_replace($config['limits'], [
            'max_cost_usd' => 1.50,
            'max_llm_calls' => 30,
            'max_search_calls' => 8,
            'max_duration_minutes' => 45,
            'max_length_revisions' => 2,
        ]);
        $config['on_budget_exceeded'] = 'manual_review';

        return AiWorkflow::query()->firstOrCreate(['slug' => 'economy-fallback'], [
            'name' => 'Economy fallback',
            'description' => 'Cheaper models, lighter research and one refinement round. Used once when a job running the Standard workflow exceeds its budget; if this budget is also exceeded the order goes to manual review.',
            'version' => 1,
            'is_default' => false,
            'is_active' => true,
            'config' => $config,
        ]);
    }

    private function standardWorkflow(AiWorkflow $economy): AiWorkflow
    {
        $config = AiWorkflow::defaultConfig();

        // Length-fit rewrites must keep the writer's voice: use the writing model.
        $config['stages']['limits']['model'] = (string) config('statementra.ai.writing_model');
        // Secondary prompts used inside stages (overridable per workflow).
        $config['stages']['writing']['prompt_keys'] = ['refinement' => 'refinement', 'revision' => 'revision'];
        $config['stages']['fact_check']['prompt_keys'] = ['fact_fix' => 'fact_fix'];
        $config['stages']['fact_check']['max_fix_passes'] = 2;
        $config['stages']['verification']['model_review'] = true;

        $workflow = AiWorkflow::query()->firstOrCreate(['slug' => 'standard'], [
            'name' => 'Standard',
            'description' => 'Full pipeline: profile, analysis, official-first research with verification, strategy, writing, editorial pass, fact check, quality review with refinement, length fitting, formatting and file QA.',
            'version' => 1,
            'is_default' => ! AiWorkflow::query()->where('is_default', true)->exists(),
            'is_active' => true,
            'config' => $config,
            'fallback_workflow_id' => $economy->id,
        ]);

        if ($workflow->fallback_workflow_id === null) {
            $workflow->forceFill(['fallback_workflow_id' => $economy->id])->save();
        }

        return $workflow;
    }

    private function prompts(): void
    {
        foreach (DefaultPrompts::all() as $key => $prompt) {
            if (PromptVersion::query()->where('prompt_key', $key)->exists()) {
                continue;
            }

            PromptVersion::query()->create([
                'prompt_key' => $key,
                'version' => 1,
                'label' => 'v1 — '.$prompt['label'],
                'description' => $prompt['description'],
                'system_prompt' => $prompt['system_prompt'],
                'user_template' => $prompt['user_template'],
                'model' => null,
                'reasoning_effort' => null,
                'status' => PromptVersion::STATUS_ACTIVE,
                'activated_at' => now(),
            ]);
        }
    }
}
