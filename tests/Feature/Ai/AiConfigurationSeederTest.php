<?php

use App\Domain\Ai\Prompts\DefaultPrompts;
use App\Domain\Ai\Prompts\PromptRenderer;
use App\Domain\Ai\Prompts\Schemas;
use App\Models\AiModelPrice;
use App\Models\AiWorkflow;
use App\Models\PromptVersion;
use Database\Seeders\AiConfigurationSeeder;

it('seeds the default workflow with an economy fallback, active v1 prompts and model prices', function () {
    $this->seed(AiConfigurationSeeder::class);

    $standard = AiWorkflow::query()->where('slug', 'standard')->sole();
    $economy = AiWorkflow::query()->where('slug', 'economy-fallback')->sole();

    expect($standard->is_default)->toBeTrue()
        ->and(AiWorkflow::default()->id)->toBe($standard->id)
        ->and($standard->fallback_workflow_id)->toBe($economy->id)
        ->and($economy->is_default)->toBeFalse()
        ->and($economy->effectiveConfig()['on_budget_exceeded'])->toBe('manual_review')
        ->and($economy->effectiveConfig()['limits']['max_cost_usd'])->toBeLessThan($standard->effectiveConfig()['limits']['max_cost_usd'])
        ->and(array_keys($standard->effectiveConfig()['stages']))->toBe(array_keys(AiWorkflow::defaultConfig()['stages']));

    foreach (Schemas::keys() as $key) {
        $prompt = PromptVersion::activeFor($key);
        expect($prompt)->not->toBeNull()
            ->and($prompt->version)->toBe(1)
            ->and($prompt->system_prompt)->toContain('untrusted_data')
            ->and($prompt->user_template)->not->toBeEmpty();
    }

    expect(AiModelPrice::query()->pluck('model')->all())->toContain('gpt-6-astra', 'gpt-6.1-sol', 'gpt-6-luna', 'gpt-5.5');
});

it('is idempotent and never overwrites what administrators changed', function () {
    $this->seed(AiConfigurationSeeder::class);

    PromptVersion::activeFor('writing')->update(['system_prompt' => 'Edited by an administrator']);
    AiWorkflow::query()->where('slug', 'standard')->update(['name' => 'Standard (edited)']);
    AiModelPrice::query()->where('model', 'gpt-6-astra')->update(['output_per_million' => 99]);

    $this->seed(AiConfigurationSeeder::class);

    expect(PromptVersion::query()->count())->toBe(count(DefaultPrompts::all()))
        ->and(AiWorkflow::query()->count())->toBe(2)
        ->and(PromptVersion::activeFor('writing')->system_prompt)->toBe('Edited by an administrator')
        ->and(AiWorkflow::query()->where('slug', 'standard')->value('name'))->toBe('Standard (edited)')
        ->and((float) AiModelPrice::query()->where('model', 'gpt-6-astra')->value('output_per_million'))->toBe(99.0);
});

it('ships a prompt and a strict schema for every task, using only variables the stages provide', function () {
    $provided = [
        'ingestion' => ['document_type', 'order_details', 'answers', 'documents', 'visual_documents'],
        'analysis' => ['document_type', 'document_focus', 'order_details', 'profile', 'requirements_documents', 'follow_up_answers', 'follow_up_allowed', 'max_questions'],
        'research' => ['search_scope', 'document_type', 'order_details', 'research_brief', 'official_domains', 'applicant_interests', 'max_claims'],
        'verification' => ['order_details', 'claims'],
        'limits' => ['document_type', 'language', 'violations', 'targets', 'counting_rules', 'required_sections', 'draft'],
    ];
    $common = ['document_type', 'document_focus', 'writing_guidance', 'language', 'limits_summary', 'format_rules', 'banned_phrases', 'target_words', 'order_details', 'requirements', 'profile', 'dossier', 'applicant_name'];
    $provided += [
        'strategy' => [...$common, 'analysis'],
        'writing' => [...$common, 'strategy'],
        'editorial' => [...$common, 'draft', 'style_findings'],
        'fact_check' => [...$common, 'draft', 'automated_findings', 'citations_allowed'],
        'fact_fix' => [...$common, 'draft', 'issues'],
        'quality_review' => [...$common, 'draft'],
        'refinement' => [...$common, 'draft', 'review'],
        'revision' => [...$common, 'delivered_document', 'revision_request'],
    ];

    expect(array_keys(DefaultPrompts::all()))->toEqualCanonicalizing(Schemas::keys());

    foreach (DefaultPrompts::all() as $key => $prompt) {
        expect(array_diff(PromptRenderer::placeholders($prompt['user_template']), $provided[$key]))->toBe([], "Prompt [{$key}] uses variables its stage does not provide.")
            ->and(PromptRenderer::placeholders($prompt['system_prompt']))->toBe([]);
    }
});
