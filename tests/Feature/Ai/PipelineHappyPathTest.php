<?php

use App\Domain\Ai\Llm\FakeProvider;
use App\Domain\Ai\PipelineDispatcher;
use App\Enums\AiJobStatus;
use App\Enums\ClaimVerificationStatus;
use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Models\AiJob;
use App\Models\AiUsage;
use App\Models\Applicant;
use App\Models\DocumentVersion;
use App\Models\OrderRequirement;
use App\Models\PromptVersion;
use App\Models\QualityReview;
use App\Models\ResearchClaim;
use App\Models\ResearchSource;
use Tests\Feature\Ai\Support\PipelineFixtures;

uses(PipelineFixtures::class);

beforeEach(fn () => $this->setUpPipeline());

it('runs a paid order through every stage to delivery with the fake provider', function () {
    $order = $this->paidOrder();

    $job = app(PipelineDispatcher::class)->startForOrder($order);

    $job->refresh();
    expect($job->status)->toBe(AiJobStatus::Completed)
        ->and($job->current_stage)->toBe(PipelineStage::Delivery)
        ->and($job->kind)->toBe(AiJob::KIND_ORDER)
        ->and($job->provider)->toBe('fake')
        ->and($job->leased_until)->toBeNull()
        ->and($job->lease_token)->toBeNull()
        ->and($job->next_run_at)->toBeNull()
        ->and($job->finished_at)->not->toBeNull();

    // Every stage checkpointed once, in order.
    $stages = $job->steps()->get()->filter(fn ($s) => $s->status === StepStatus::Completed)->map(fn ($s) => $s->stage->value)->values()->all();
    expect($stages)->toBe(array_map(fn (PipelineStage $s) => $s->value, PipelineStage::ordered()));

    // Order moved through the lifecycle and is waiting for the email provider.
    expect($order->fresh()->status)->toBe(OrderStatus::DeliveryPending)
        ->and($order->fresh()->applicant_name)->toBe('Ama Mensah')
        ->and($order->fresh()->language_variant)->toBe('en-GB')
        ->and($order->statusHistories()->pluck('to_status')->map(fn ($s) => $s->value)->all())
        ->toContain('RESEARCHING', 'RESEARCH_COMPLETE', 'WRITING', 'QUALITY_REVIEW', 'FINAL_REVIEW', 'DELIVERY_PENDING');

    // Applicant profile: cited facts only.
    $applicant = Applicant::query()->where('order_id', $order->id)->firstOrFail();
    expect($applicant->full_name)->toBe('Ama Mensah')
        ->and($applicant->profile['facts'])->not->toBeEmpty()
        ->and(collect($applicant->profile['facts'])->pluck('source_ref')->unique()->values()->all())->each->toBeIn(['why_field', 'background', 'experience', 'goals']);

    // Research dossier: official sources verified and safe; requirements logged.
    expect(ResearchSource::query()->where('order_id', $order->id)->where('is_official', true)->exists())->toBeTrue()
        ->and(ResearchClaim::query()->where('ai_job_id', $job->id)->where('verification_status', ClaimVerificationStatus::Verified->value)->where('safe_to_use', true)->count())->toBeGreaterThan(0)
        ->and(OrderRequirement::query()->where('order_id', $order->id)->where('ai_job_id', $job->id)->exists())->toBeTrue()
        ->and(QualityReview::query()->where('ai_job_id', $job->id)->where('passed', true)->exists())->toBeTrue();

    // A version was created, rendered, validated and handed to delivery.
    $version = DocumentVersion::query()->where('order_id', $order->id)->sole();
    expect($version->qa_status)->toBe('passed')
        ->and($version->ai_job_id)->toBe($job->id)
        ->and((float) $version->quality_score)->toBeGreaterThanOrEqual(8.0)
        ->and($version->content['subtitle'])->toBe('MSc Computer Science — University of Oxford')
        ->and($this->engine['delivered'])->toHaveCount(1)
        ->and($this->engine['delivered'][0]['version'])->toBe($version->id);

    // The essay is built from the applicant's own material.
    expect($version->plain_text)->toContain('crop disease classifier')->toContain('University of Oxford');

    // Versions used are recorded.
    expect($job->prompt_versions)->toHaveKeys(['ingestion', 'analysis', 'research', 'writing', 'quality_review'])
        ->and($job->prompt_versions['writing']['id'])->toBe(PromptVersion::activeFor('writing')->id)
        ->and($job->workflow_snapshot['_workflow']['slug'])->toBe('standard');

    // Usage and cost recorded for every model call.
    expect(AiUsage::query()->where('ai_job_id', $job->id)->count())->toBe($job->llm_calls)
        ->and($job->llm_calls)->toBe(count(FakeProvider::calls()))
        ->and((float) $job->total_cost_usd)->toBeGreaterThan(0.0);
});
