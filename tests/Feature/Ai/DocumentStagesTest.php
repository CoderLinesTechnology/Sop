<?php

use App\Domain\Ai\Pipeline\PipelineTrigger;
use App\Domain\Ai\PipelineDispatcher;
use App\Enums\AiJobStatus;
use App\Models\AiJob;
use App\Models\AiJobStep;
use App\Models\AiWorkflow;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use Tests\Feature\Ai\Support\PipelineFixtures;
use Tests\Feature\Ai\Support\RecordingTrigger;

uses(PipelineFixtures::class);

it('re-renders once when file QA fails and then delivers', function () {
    $this->setUpPipeline(qaFailures: 1);

    $job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());

    $qa = AiJobStep::query()->where('ai_job_id', $job->id)->where('stage', 'file_qa')->sole();
    expect($job->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and($qa->output['rerendered'])->toBeTrue()
        ->and($this->engine['rendered'])->toBe(2)
        ->and($this->engine['qa'])->toBe(2)
        ->and($this->engine['delivered'])->toHaveCount(1);
});

it('hands files that keep failing QA to a person instead of delivering them', function () {
    $this->setUpPipeline(qaFailures: 2);

    $order = $this->paidOrder();
    $job = app(PipelineDispatcher::class)->startForOrder($order);

    expect($job->fresh()->status)->toBe(AiJobStatus::ManualReview)
        ->and($job->fresh()->last_error_code)->toBe('file_qa_failed')
        ->and($job->fresh()->last_error_message)->toContain('PDF text differs')
        ->and($this->engine['delivered'])->toBe([])
        ->and($order->fresh()->status->value)->toBe('MANUAL_REVIEW');
});

it('titles the document from the template pattern', function () {
    $this->setUpPipeline();
    DocumentTemplate::query()->where('slug', 'classic')->update(['title_template' => '{document_type}: {programme}']);

    $order = $this->paidOrder();
    app(PipelineDispatcher::class)->startForOrder($order);

    expect(DocumentVersion::query()->where('order_id', $order->id)->value('content')['title'])->toBe('Personal Statement: MSc Computer Science');
});

it('skips stages a workflow disables and records why', function () {
    $this->setUpPipeline();
    $workflow = AiWorkflow::query()->where('slug', 'standard')->sole();
    $config = $workflow->config;
    $config['stages']['strategy']['enabled'] = false;
    $config['stages']['editorial']['enabled'] = false;
    $config['stages']['writing']['enabled'] = false; // required: ignored
    $workflow->update(['config' => $config]);

    $job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());

    $skipped = AiJobStep::query()->where('ai_job_id', $job->id)->where('status', 'skipped')->pluck('stage')->map->value->all();
    expect($job->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and($skipped)->toEqualCanonicalizing(['strategy', 'editorial'])
        ->and(AiJobStep::query()->where('ai_job_id', $job->id)->where('stage', 'writing')->where('status', 'completed')->exists())->toBeTrue();
});

it('runs a pipeline from the command line through the same worker', function () {
    $this->setUpPipeline();
    app()->instance(PipelineTrigger::class, new RecordingTrigger);
    $order = $this->paidOrder();

    $this->artisan('ai:run-pipeline', ['order' => $order->reference, '--start' => true])
        ->expectsOutputToContain('Running job')
        ->assertSuccessful();

    expect(AiJob::query()->where('order_id', $order->id)->sole()->status)->toBe(AiJobStatus::Completed);
});
