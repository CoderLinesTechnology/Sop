<?php

use App\Domain\Ai\Pipeline\PipelineTrigger;
use App\Domain\Ai\PipelineDispatcher;
use App\Enums\AiJobStatus;
use App\Models\AiJob;
use App\Support\Runtime\BackgroundProcess;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Feature\Ai\Support\PipelineFixtures;
use Tests\Feature\Ai\Support\RecordingTrigger;

uses(PipelineFixtures::class);

beforeEach(fn () => $this->setUpPipeline());

/** The trigger as the web runtime sees it with RUNTIME_PIPELINE_DRIVER=process (tests run in the console). */
class WebProcessTrigger extends PipelineTrigger
{
    protected function runsInProcesses(): bool
    {
        return true;
    }
}

it('drives a job to completion from the command-line worker', function () {
    app()->instance(PipelineTrigger::class, new RecordingTrigger);
    $job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());
    expect($job->fresh()->llm_calls)->toBe(0); // only recorded, not run

    $this->artisan('statementra:pipeline-run', ['job' => $job->uuid])->assertSuccessful();

    expect($job->fresh()->status)->toBe(AiJobStatus::Completed);
});

it('leaves a job alone while another process holds it, and refuses unknown jobs', function () {
    app()->instance(PipelineTrigger::class, new RecordingTrigger);
    $job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());
    AiJob::query()->whereKey($job->id)->update(['leased_until' => now()->addMinutes(10), 'lease_token' => Str::random(32)]);

    $this->artisan('statementra:pipeline-run', ['job' => $job->uuid])->assertSuccessful();
    expect($job->fresh()->llm_calls)->toBe(0);

    $this->artisan('statementra:pipeline-run', ['job' => (string) Str::uuid()])->assertFailed();
    $this->artisan('statementra:pipeline-run', ['job' => 'not-a-uuid'])->assertFailed();
});

it('starts one detached worker per job from web requests instead of working in the request', function () {
    app()->instance(PipelineTrigger::class, new WebProcessTrigger);
    $started = [];
    $this->mock(BackgroundProcess::class, function (MockInterface $mock) use (&$started) {
        $mock->shouldReceive('artisan')->andReturnUsing(function (array $arguments) use (&$started) {
            $started[] = $arguments;

            return true;
        });
    });

    $job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());
    app(PipelineTrigger::class)->kick($job); // e.g. the status page polling a moment later
    expect(app(PipelineTrigger::class)->continueElsewhere($job))->toBeTrue(); // the heartbeat path

    expect($started)->toBe([['statementra:pipeline-run', $job->uuid]])
        ->and($job->fresh()->llm_calls)->toBe(0);
});

it('falls back to working in the request when no process can be started', function () {
    app()->instance(PipelineTrigger::class, new WebProcessTrigger);
    $this->mock(BackgroundProcess::class, fn (MockInterface $mock) => $mock->shouldReceive('artisan')->andReturn(false));

    $job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());

    expect($job->fresh()->status)->toBe(AiJobStatus::Completed);
});
