<?php

use App\Domain\Ai\Llm\FakeProvider;
use App\Domain\Ai\Llm\LlmRequest;
use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Ai\Samples\WritingSampleSelector;
use App\Enums\AiJobStatus;
use App\Enums\DocumentKind;
use App\Models\AiJobStep;
use App\Models\AiWorkflow;
use App\Models\DocumentVersion;
use App\Models\Order;
use App\Models\WritingSample;
use Tests\Feature\Ai\Support\PipelineFixtures;

uses(PipelineFixtures::class);

beforeEach(fn () => $this->setUpPipeline());

function writingSample(array $attributes = []): WritingSample
{
    return WritingSample::query()->create($attributes + [
        'title' => 'Kofi Asante, Edinburgh SOP',
        'document_kind' => DocumentKind::PersonalStatement->value,
        'degree_level' => 'masters',
        'field_of_study' => 'Computer Science',
        'country_code' => 'GB',
        'notes' => 'Opens with a concrete moment instead of a summary.',
        'content' => str_repeat('A sample paragraph about building a careful data pipeline for a small clinic. ', 12),
        'word_count' => 150,
        'source' => WritingSample::SOURCE_PASTED,
        'rights_confirmed_at' => now(),
    ]);
}

it('shows matching samples to the planning, writing and editing calls only', function () {
    $match = writingSample(['content' => 'SAMPLE-ALPHA opens with the night the lab generator failed. '.str_repeat('More detail follows. ', 40)]);
    writingSample(['document_kind' => DocumentKind::Resume->value, 'content' => 'SAMPLE-RESUME '.str_repeat('Led a team. ', 40)]);
    writingSample(['is_active' => false, 'content' => 'SAMPLE-INACTIVE '.str_repeat('Retired text. ', 40)]);

    $job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());

    expect($job->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and($job->fresh()->writing_sample_ids)->toBe([$match->id]);

    foreach (['strategy', 'writing', 'editorial'] as $key) {
        $request = FakeProvider::calls($key)[0];
        expect($request->userText())
            ->toContain('SAMPLE-ALPHA')
            ->toContain('<untrusted_data source="writing_samples"')
            ->toContain('Opens with a concrete moment')
            ->not->toContain('SAMPLE-RESUME')
            ->not->toContain('SAMPLE-INACTIVE')
            ->not->toContain('Kofi Asante')
            ->and($request->instructions)->toContain('## Writing samples')->toContain('Never copy');
    }

    foreach (['ingestion', 'analysis', 'fact_check', 'quality_review'] as $key) {
        expect(FakeProvider::calls($key)[0]->userText())->not->toContain('SAMPLE-ALPHA')
            ->and(FakeProvider::calls($key)[0]->instructions)->not->toContain('## Writing samples');
    }
});

it('shows no samples when the workflow turns them off', function () {
    writingSample(['content' => 'SAMPLE-ALPHA '.str_repeat('More detail follows. ', 40)]);
    $workflow = AiWorkflow::query()->where('slug', 'standard')->firstOrFail();
    $workflow->update(['config' => ['writing_samples' => ['enabled' => false]] + (array) $workflow->config]);

    $job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());

    expect($job->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and($job->fresh()->writing_sample_ids)->toBe([])
        ->and(FakeProvider::calls('writing')[0]->userText())->not->toContain('SAMPLE-ALPHA')
        ->and(FakeProvider::calls('writing')[0]->instructions)->not->toContain('## Writing samples');
});

it('removes wording the writer copied from a sample before delivery', function () {
    $copied = 'Watching a cholera map refresh in a district office taught me that public health depends on patient data work.';
    writingSample(['content' => 'An opening line of the sample. '.$copied.' '.str_repeat('Other sample sentences follow here. ', 30)]);

    FakeProvider::queue('writing', function (LlmRequest $request, Closure $default) use ($copied) {
        $draft = $default();
        $draft['blocks'][1]['text'] .= ' '.$copied;

        return $draft;
    });

    $order = $this->paidOrder();
    $job = app(PipelineDispatcher::class)->startForOrder($order);

    $factCheck = AiJobStep::query()->where('ai_job_id', $job->id)->where('stage', 'fact_check')->sole();
    $delivered = (string) DocumentVersion::query()->where('order_id', $order->id)->orderByDesc('id')->value('plain_text');

    expect($job->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and($factCheck->output['passes'][0]['automated'])->toBeGreaterThan(0)
        ->and($delivered)->not->toContain('cholera map')
        ->and($delivered)->toContain('87% accuracy')
        ->and(FakeProvider::calls('fact_fix')[0]->userText())->toContain('repeats the wording of a writing sample');
});

it('keeps the samples it chose for the whole job', function () {
    $first = writingSample();
    $order = $this->paidOrder();
    $job = app(PipelineDispatcher::class)->startForOrder($order);

    writingSample(['priority' => 99]);
    $first->update(['is_active' => false]);

    $samples = app(WritingSampleSelector::class)->forJob($job->fresh(), $order, DocumentKind::PersonalStatement);
    expect($samples->pluck('id')->all())->toBe([$first->id])
        // A sample switched off since then is still known to the copy check, but no longer shown.
        ->and(WritingSampleSelector::forPrompt($samples))->toBe([]);
});

it('prefers the closest field, level and country, then priority', function () {
    $order = $this->paidOrder(['degree_level' => "Master's"]); // MSc Computer Science, GB
    $cs = writingSample();
    $csUs = writingSample(['country_code' => 'US']);
    $history = writingSample(['field_of_study' => 'History', 'priority' => 50]);
    writingSample(['field_of_study' => null, 'degree_level' => null, 'country_code' => null]);
    $selector = app(WritingSampleSelector::class);

    expect($selector->choose($order, DocumentKind::PersonalStatement, 3))->toBe([$cs->id, $csUs->id, $history->id])
        ->and($selector->choose($order, DocumentKind::PersonalStatement, 0))->toBe([])
        ->and($selector->choose($order, DocumentKind::Resume, 3))->toBe([]);
});

it('takes turns between equally good samples across orders', function () {
    $a = writingSample();
    $b = writingSample();
    $selector = app(WritingSampleSelector::class);

    $firstChoices = collect(range(1, 24))
        ->map(fn (int $i) => $selector->choose(Order::factory()->make(['public_id' => 'order-'.$i, 'programme' => 'MSc Computer Science', 'degree_level' => 'masters', 'country_code' => 'GB']), DocumentKind::PersonalStatement, 1)[0])
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($firstChoices)->toBe([$a->id, $b->id]);
});
