<?php

use App\Domain\Ai\Llm\FakeProvider;
use App\Domain\Ai\Llm\LlmException;
use App\Domain\Ai\Llm\LlmRequest;
use App\Domain\Ai\Llm\LlmResponse;
use App\Domain\Ai\Pipeline\PipelineWorker;
use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Notifications\AdminNotifier;
use App\Enums\AiJobStatus;
use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Models\AiJob;
use App\Models\AiJobStep;
use App\Models\AiUsage;
use App\Models\AiWorkflow;
use App\Support\Settings;
use Mockery\MockInterface;
use Tests\Feature\Ai\Support\PipelineFixtures;

uses(PipelineFixtures::class);

beforeEach(fn () => $this->setUpPipeline());
afterEach(fn () => Settings::flush());

function researchSteps(AiJob $job, string $stage = 'research'): array
{
    return AiJobStep::query()->where('ai_job_id', $job->id)->where('stage', $stage)->orderBy('id')
        ->get()->map(fn ($s) => [$s->attempt, $s->status->value, $s->error_code])->all();
}

it('retries a failed research stage after a backoff and then succeeds', function () {
    FakeProvider::queue('research', LlmException::server(503, 'Service unavailable'));
    $order = $this->paidOrder();

    $job = app(PipelineDispatcher::class)->startForOrder($order);

    $job->refresh();
    expect($job->status)->toBe(AiJobStatus::Running)
        ->and($job->current_stage)->toBe(PipelineStage::Research)
        ->and($job->leased_until)->toBeNull()
        ->and($job->next_run_at->between(now()->addSeconds(29), now()->addSeconds(31)))->toBeTrue()
        ->and(researchSteps($job))->toBe([[1, 'failed', 'server_error']])
        ->and($job->last_error_code)->toBe('server_error');

    // Not due yet: a trigger does nothing.
    expect(app(PipelineWorker::class)->drive($job->id))->toBe(PipelineWorker::BUSY);

    $this->travel(31)->seconds();
    app(PipelineWorker::class)->work($job->id);

    expect($job->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and(researchSteps($job))->toBe([[1, 'failed', 'server_error'], [2, 'completed', null]]);
});

it('backs off exponentially after model timeouts', function () {
    FakeProvider::queue('writing', LlmException::timeout(), LlmException::timeout());
    $order = $this->paidOrder();
    $job = app(PipelineDispatcher::class)->startForOrder($order);

    expect($job->fresh()->next_run_at->diffInSeconds(now(), true))->toBeBetween(29, 31);

    $this->travel(31)->seconds();
    app(PipelineWorker::class)->work($job->id);

    expect($job->fresh()->current_stage)->toBe(PipelineStage::Writing)
        ->and($job->fresh()->next_run_at->diffInSeconds(now(), true))->toBeBetween(119, 121);

    $this->travel(121)->seconds();
    app(PipelineWorker::class)->work($job->id);

    expect($job->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and(researchSteps($job, 'writing'))->toBe([[1, 'failed', 'timeout'], [2, 'failed', 'timeout'], [3, 'completed', null]]);
});

it('sends the order to manual review when a stage keeps failing', function () {
    $this->mock(AdminNotifier::class, fn (MockInterface $mock) => $mock->shouldReceive('aiJobFailed')->once()->withArgs(fn ($order, $reason) => str_contains($reason, 'Deep research') && str_contains($reason, '3 attempt')));

    FakeProvider::always('research', LlmException::rateLimited(null));
    $order = $this->paidOrder();
    $job = app(PipelineDispatcher::class)->startForOrder($order);

    foreach ([31, 121] as $seconds) {
        $this->travel($seconds)->seconds();
        app(PipelineWorker::class)->work($job->id);
    }

    $job->refresh();
    expect($job->status)->toBe(AiJobStatus::Failed)
        ->and($job->next_run_at)->toBeNull()
        ->and($job->failure_count)->toBe(3)
        ->and($order->fresh()->status)->toBe(OrderStatus::ManualReview)
        ->and($order->statusHistories()->pluck('to_status')->map->value->all())->toContain('PROCESSING_FAILED', 'MANUAL_REVIEW');
});

it('re-asks once to repair invalid structured output, then hands over to a person', function () {
    $this->mock(AdminNotifier::class, fn (MockInterface $mock) => $mock->shouldReceive('aiJobFailed')->once());

    FakeProvider::queue('strategy', '{"central_thread": "missing every other field"}', '{"central_thread": "still missing fields"}');
    $order = $this->paidOrder();

    $job = app(PipelineDispatcher::class)->startForOrder($order);

    $job->refresh();
    $calls = FakeProvider::calls('strategy');
    expect($calls)->toHaveCount(2)
        ->and($calls[1]->userText())->toContain('REPAIR REQUIRED')->toContain('missing required property')
        ->and($job->status)->toBe(AiJobStatus::Failed)
        ->and($job->last_error_code)->toBe('invalid_output')
        ->and(researchSteps($job, 'strategy'))->toBe([[1, 'failed', 'invalid_output']])
        ->and($order->fresh()->status)->toBe(OrderStatus::ManualReview);
});

it('continues when the repaired output is valid', function () {
    FakeProvider::queue('strategy', 'not json at all');
    $job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());

    expect($job->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and(FakeProvider::calls('strategy'))->toHaveCount(2);
});

it('retries once with a larger output budget when a response is cut off', function () {
    FakeProvider::queue('writing', FakeProvider::incompleteResponse());
    $job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());

    $calls = FakeProvider::calls('writing');
    expect($job->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and($calls)->toHaveCount(2)
        ->and($calls[1]->maxOutputTokens)->toBe($calls[0]->maxOutputTokens * 2)
        ->and(AiUsage::query()->where('ai_job_id', $job->id)->where('status', 'incomplete')->count())->toBe(1);
});

it('switches once to the fallback workflow when the budget is exceeded', function () {
    $standard = AiWorkflow::query()->where('slug', 'standard')->firstOrFail();
    $standard->update(['config' => array_replace_recursive($standard->config, ['limits' => ['max_llm_calls' => 3]])]);
    $economy = AiWorkflow::query()->where('slug', 'economy-fallback')->firstOrFail();

    $job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());

    $job->refresh();
    expect($job->status)->toBe(AiJobStatus::Completed)
        ->and($job->used_fallback)->toBeTrue()
        ->and($job->ai_workflow_id)->toBe($economy->id)
        ->and($job->workflow_snapshot['_workflow']['slug'])->toBe('economy-fallback')
        ->and($job->workflow_snapshot['_fallback_from']['slug'])->toBe('standard')
        ->and($job->workflow_snapshot['_budget_offset']['llm_calls'])->toBe(3)
        ->and(AiJobStep::query()->where('ai_job_id', $job->id)->where('status', StepStatus::Superseded->value)->value('error_code'))->toBe('budget_exceeded');

    // Stages after the switch run on the economy workflow's models.
    expect(collect(FakeProvider::calls('research'))->first()->model)->toBe('gpt-6.1-sol')
        ->and(collect(FakeProvider::calls('research'))->last()->model)->toBe('gpt-6-luna')
        ->and(collect(FakeProvider::calls('writing'))->first()->model)->toBe('gpt-6.1-sol')
        ->and(collect(FakeProvider::calls('quality_review'))->first()->model)->toBe('gpt-6-luna');
});

it('goes to manual review when the fallback budget is exceeded too', function () {
    $this->mock(AdminNotifier::class, fn (MockInterface $mock) => $mock->shouldReceive('manualReviewRequired')->once()->withArgs(fn ($order, $reason) => str_contains($reason, 'budget')));

    foreach (AiWorkflow::all() as $workflow) {
        $workflow->update(['config' => array_replace_recursive($workflow->config, ['limits' => ['max_llm_calls' => 2]])]);
    }

    $order = $this->paidOrder();
    $job = app(PipelineDispatcher::class)->startForOrder($order);

    expect($job->fresh()->status)->toBe(AiJobStatus::ManualReview)
        ->and($job->fresh()->used_fallback)->toBeTrue()
        ->and($job->fresh()->last_error_code)->toBe('budget_exceeded')
        ->and($order->fresh()->status)->toBe(OrderStatus::ManualReview);
});

it('stops at the platform daily budget without falling back', function () {
    Settings::set('ai.daily_budget_usd', 0.02);
    $order = $this->paidOrder();

    $job = app(PipelineDispatcher::class)->startForOrder($order);

    expect($job->fresh()->status)->toBe(AiJobStatus::ManualReview)
        ->and($job->fresh()->used_fallback)->toBeFalse()
        ->and($job->fresh()->last_error_message)->toContain('daily AI budget');
});

it('enforces the job cost limit using recorded usage', function () {
    $standard = AiWorkflow::query()->where('slug', 'standard')->firstOrFail();
    $standard->update(['config' => array_replace_recursive($standard->config, ['limits' => ['max_cost_usd' => 0.01], 'on_budget_exceeded' => 'manual_review'])]);

    $job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());

    expect($job->fresh()->status)->toBe(AiJobStatus::ManualReview)
        ->and($job->fresh()->used_fallback)->toBeFalse()
        ->and((float) $job->fresh()->total_cost_usd)->toBeGreaterThanOrEqual(0.01);
});

it('records tokens, searches and estimated cost for every call', function () {
    FakeProvider::queue('ingestion', function (LlmRequest $request, Closure $default) {
        $output = json_encode($default(), JSON_UNESCAPED_UNICODE);

        return LlmResponse::fromApi([
            'id' => 'resp_test_1',
            'model' => 'gpt-6.1-sol',
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $output, 'annotations' => []]]]],
            'usage' => [
                'input_tokens' => 10_000,
                'input_tokens_details' => ['cached_tokens' => 4_000],
                'output_tokens' => 2_000,
                'output_tokens_details' => ['reasoning_tokens' => 500],
            ],
        ]);
    });

    $job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());

    $usage = AiUsage::query()->where('ai_job_id', $job->id)->where('stage', 'ingestion')->sole();
    // 6,000 × $2/M + 4,000 × $0.20/M + 2,000 × $16/M
    expect($usage->provider)->toBe('fake')
        ->and($usage->model)->toBe('gpt-6.1-sol')
        ->and($usage->response_id)->toBe('resp_test_1')
        ->and($usage->input_tokens)->toBe(10_000)
        ->and($usage->cached_input_tokens)->toBe(4_000)
        ->and($usage->output_tokens)->toBe(2_000)
        ->and($usage->reasoning_tokens)->toBe(500)
        ->and((float) $usage->estimated_cost_usd)->toBe(0.0448)
        ->and($usage->prompt_version_id)->toBe($job->prompt_versions['ingestion']['id'])
        ->and($usage->status)->toBe('success');

    $research = AiUsage::query()->where('ai_job_id', $job->id)->where('stage', 'research')->get();
    $job->refresh();
    expect($research->sum('search_calls'))->toBeGreaterThan(0)
        ->and($job->search_calls)->toBe((int) AiUsage::query()->where('ai_job_id', $job->id)->sum('search_calls'))
        ->and($job->total_cached_tokens)->toBe(4_000)
        ->and($job->total_reasoning_tokens)->toBe(500)
        ->and(round((float) $job->total_cost_usd, 6))->toBe(round((float) AiUsage::query()->where('ai_job_id', $job->id)->sum('estimated_cost_usd'), 6))
        ->and((float) AiJobStep::query()->where('ai_job_id', $job->id)->where('stage', 'ingestion')->value('cost_usd'))->toBe(0.0448);
});
