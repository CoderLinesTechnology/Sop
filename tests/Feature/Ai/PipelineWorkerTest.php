<?php

use App\Domain\Ai\Llm\FakeProvider;
use App\Domain\Ai\Llm\LlmException;
use App\Domain\Ai\Pipeline\PipelineRunner;
use App\Domain\Ai\Pipeline\PipelineTrigger;
use App\Domain\Ai\Pipeline\PipelineWorker;
use App\Domain\Ai\PipelineDispatcher;
use App\Enums\AiJobStatus;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Models\AiJob;
use App\Models\AiJobStep;
use Tests\Feature\Ai\Support\PipelineFixtures;
use Tests\Feature\Ai\Support\RecordingTrigger;

uses(PipelineFixtures::class);

beforeEach(function () {
    $this->setUpPipeline();
    $this->trigger = new RecordingTrigger;
    app()->instance(PipelineTrigger::class, $this->trigger);
    $this->job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());
});

it('claims a lease atomically and only when the job is due and unleased', function () {
    $worker = app(PipelineWorker::class);

    $token = $worker->claim($this->job->id);
    expect($token)->toHaveLength(40)
        ->and($worker->claim($this->job->id))->toBeNull()
        ->and($this->job->fresh()->lease_token)->toBe($token)
        ->and($this->job->fresh()->leased_until->diffInMinutes(now(), true))->toBeGreaterThan(14.9);

    // An expired lease can be taken over.
    $this->travel(PipelineWorker::LEASE_MINUTES + 1)->minutes();
    expect($worker->claim($this->job->id))->not->toBeNull();

    // A job that is not due yet is only claimable without the due check.
    AiJob::query()->whereKey($this->job->id)->update(['leased_until' => null, 'lease_token' => null, 'next_run_at' => now()->addMinute()]);
    expect($worker->claim($this->job->id))->toBeNull()
        ->and($worker->claim($this->job->id, requireDue: false))->not->toBeNull();

    // Finished jobs are never claimable.
    AiJob::query()->whereKey($this->job->id)->update(['leased_until' => null, 'next_run_at' => null, 'status' => AiJobStatus::Completed->value]);
    expect($worker->claim($this->job->id, requireDue: false))->toBeNull();
});

it('releases the lease and persists the next stage after each stage', function () {
    app(PipelineWorker::class)->drive($this->job->id, budgetSeconds: 0);

    $job = $this->job->fresh();
    expect($job->current_stage)->toBe(PipelineStage::Analysis)
        ->and($job->status)->toBe(AiJobStatus::Running)
        ->and($job->leased_until)->toBeNull()
        ->and($job->lease_token)->toBeNull()
        ->and($job->next_run_at->isFuture())->toBeFalse()
        ->and($job->heartbeat_at)->not->toBeNull()
        ->and(AiJobStep::query()->where('ai_job_id', $job->id)->pluck('stage')->map->value->all())->toBe(['ingestion']);
});

it('hands the rest of the work to a fresh request when its time budget runs out', function () {
    app(PipelineWorker::class)->work($this->job->id, budgetSeconds: 0);

    expect($this->trigger->continued)->toBe([$this->job->id])
        ->and($this->job->fresh()->current_stage)->toBe(PipelineStage::Analysis);

    // The continuation (normally the signed loopback request) finishes the job.
    app(PipelineWorker::class)->work($this->job->id);
    expect($this->job->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and($this->trigger->continued)->toHaveCount(1);
});

it('lets a stage that ran out of a shortened budget continue later without counting an attempt', function () {
    // Too little of the request's time is left for a model call: the stage yields.
    $next = app(PipelineRunner::class)->run($this->job->id, 'ingestion', 60);

    $step = AiJobStep::query()->where('ai_job_id', $this->job->id)->sole();
    expect($next->stage)->toBe(PipelineStage::Ingestion)
        ->and($next->delaySeconds)->toBe(0)
        ->and($step->status)->toBe(StepStatus::Superseded)
        ->and($step->error_code)->toBe('stage_time_exhausted')
        ->and($this->job->fresh()->failure_count)->toBe(0)
        ->and(FakeProvider::calls())->toBe([]);

    // With a full budget a timeout is a real, counted attempt retried after a backoff.
    FakeProvider::queue('ingestion', LlmException::timeout());
    $retry = app(PipelineRunner::class)->run($this->job->id, 'ingestion');

    expect($retry->delaySeconds)->toBe(30)
        ->and($this->job->fresh()->failure_count)->toBe(1)
        ->and(AiJobStep::query()->where('ai_job_id', $this->job->id)->orderBy('id')->pluck('attempt')->all())->toBe([1, 2]);
});

it('ignores stale triggers for a stage the job has already left', function () {
    app(PipelineWorker::class)->drive($this->job->id, budgetSeconds: 0);

    expect(app(PipelineRunner::class)->run($this->job->id, 'ingestion'))->toBeNull()
        ->and(AiJobStep::query()->where('ai_job_id', $this->job->id)->count())->toBe(1);
});

it('runs a job to the end inline for the command line, skipping retry delays', function () {
    FakeProvider::queue('research', LlmException::server(502, 'Bad gateway'));

    $job = app(PipelineWorker::class)->runToCompletion($this->job);

    expect($job->status)->toBe(AiJobStatus::Completed)
        ->and(AiJobStep::query()->where('ai_job_id', $job->id)->where('stage', 'research')->orderBy('id')->pluck('status')->map->value->all())->toBe(['failed', 'completed']);
});
