<?php

use App\Domain\Ai\Llm\FakeProvider;
use App\Domain\Ai\Llm\LlmException;
use App\Domain\Ai\Pipeline\PipelineTrigger;
use App\Domain\Ai\Pipeline\PipelineWorker;
use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Orders\FulfillmentDenied;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\AiJobStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Models\AdminUser;
use App\Models\AiJob;
use App\Models\AuditLog;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Ai\Support\PipelineFixtures;
use Tests\Feature\Ai\Support\RecordingTrigger;

uses(PipelineFixtures::class);

beforeEach(function () {
    $this->setUpPipeline();
    $this->admin = AdminUser::factory()->create();
});

function recordingTrigger(): RecordingTrigger
{
    $trigger = new RecordingTrigger;
    app()->instance(PipelineTrigger::class, $trigger);

    return $trigger;
}

it('starts one pipeline per order even when called twice', function () {
    $order = $this->paidOrder();
    $dispatcher = app(PipelineDispatcher::class);

    $first = $dispatcher->startForOrder($order);
    $second = $dispatcher->startForOrder($order->fresh());

    expect($second->id)->toBe($first->id)
        ->and(AiJob::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and($first->dedupe_key)->toBe("order:{$order->id}:pipeline")
        ->and($order->fresh()->fulfillment_started_at)->not->toBeNull();
});

it('creates the job with a workflow and prompt snapshot and kicks it after commit', function () {
    $trigger = recordingTrigger();
    $order = $this->paidOrder();

    $job = app(PipelineDispatcher::class)->startForOrder($order);

    expect($trigger->kicked)->toBe([$job->id])
        ->and($job->status)->toBe(AiJobStatus::Queued)
        ->and($job->current_stage)->toBe(PipelineStage::Ingestion)
        ->and($job->next_run_at)->not->toBeNull()
        ->and($job->workflow_snapshot['_workflow']['slug'])->toBe('standard')
        ->and($job->workflow_snapshot['limits']['max_stage_attempts'])->toBe(3)
        ->and(array_keys($job->prompt_versions))->toContain('ingestion', 'writing', 'fact_fix', 'refinement', 'revision');
});

it('waits for the surrounding transaction to commit before running', function () {
    $order = $this->paidOrder();

    DB::transaction(function () use ($order, &$job) {
        $job = app(PipelineDispatcher::class)->startForOrder($order);
        expect($job->fresh()->status)->toBe(AiJobStatus::Queued);
    });

    expect($job->fresh()->status)->toBe(AiJobStatus::Completed);
});

it('refuses to start an unpaid order', function () {
    $order = Order::factory()->create(['status' => OrderStatus::PaymentPending->value]);

    expect(fn () => app(PipelineDispatcher::class)->startForOrder($order))
        ->toThrow(FulfillmentDenied::class);

    expect(AiJob::query()->count())->toBe(0)
        ->and($order->fresh()->fulfillment_started_at)->toBeNull();
});

it('refuses a payment that does not match the order and keeps the audit entry', function () {
    $order = $this->paidOrder();
    $order->successfulPayment()->first()->forceFill(['amount' => 100])->save();

    expect(fn () => app(PipelineDispatcher::class)->startForOrder($order->fresh()))
        ->toThrow(fn (FulfillmentDenied $e) => expect($e->reason)->toBe('payment_mismatch'));

    expect(AiJob::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'fulfillment.denied')->exists())->toBeTrue();
});

it('pauses between stages and resumes where it stopped', function () {
    $trigger = recordingTrigger();
    $order = $this->paidOrder();
    $dispatcher = app(PipelineDispatcher::class);
    $worker = app(PipelineWorker::class);

    $job = $dispatcher->startForOrder($order);
    $dispatcher->pause($order, $this->admin, 'Checking the CV with the customer');

    expect($job->fresh()->status)->toBe(AiJobStatus::Paused)
        ->and($order->fresh()->paused_at)->not->toBeNull()
        ->and($worker->drive($job->id))->toBe(PipelineWorker::BUSY)
        ->and($job->steps()->count())->toBe(0);

    $dispatcher->resume($order->fresh(), 'Customer confirmed', $this->admin);

    expect($order->fresh()->paused_at)->toBeNull()
        ->and($job->fresh()->status)->toBe(AiJobStatus::Running)
        ->and($trigger->kicked)->toBe([$job->id, $job->id]);

    $worker->drive($job->id);

    expect($job->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and(AuditLog::query()->whereIn('action', ['ai.pipeline_paused', 'ai.pipeline_resumed'])->count())->toBe(2);
});

it('stops after the running stage when paused mid-run', function () {
    recordingTrigger();
    $order = $this->paidOrder();
    $job = app(PipelineDispatcher::class)->startForOrder($order);

    // The admin pauses while the analysis stage is running.
    FakeProvider::queue('analysis', function ($request, $default) use ($order) {
        app(PipelineDispatcher::class)->pause($order, null, 'Paused mid-stage');

        return $default();
    });

    app(PipelineWorker::class)->drive($job->id);

    $job->refresh();
    expect($job->status)->toBe(AiJobStatus::Paused)
        ->and($job->current_stage)->toBe(PipelineStage::Research)
        ->and($job->next_run_at)->toBeNull()
        ->and($job->leased_until)->toBeNull()
        ->and($job->stageOutput(PipelineStage::Analysis))->not->toBeNull();
});

it('retries the failed stage with a fresh attempt counter', function () {
    $order = $this->paidOrder();
    FakeProvider::queue('research', LlmException::client(400, 'Invalid tool configuration'));

    $job = app(PipelineDispatcher::class)->startForOrder($order);

    expect($job->fresh()->status)->toBe(AiJobStatus::Failed)
        ->and($order->fresh()->status)->toBe(OrderStatus::ManualReview);

    app(PipelineDispatcher::class)->retry($order->fresh(), $this->admin);

    $job->refresh();
    expect($job->status)->toBe(AiJobStatus::Completed)
        ->and($job->steps()->where('stage', 'research')->where('status', StepStatus::Superseded->value)->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::DeliveryPending)
        ->and($order->statusHistories()->where('to_status', OrderStatus::Researching->value)->where('actor_type', 'admin')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'ai.pipeline_retried')->where('admin_user_id', $this->admin->id)->exists())->toBeTrue();
});

it('only retries failed or manual-review runs', function () {
    recordingTrigger();
    $order = $this->paidOrder();
    app(PipelineDispatcher::class)->startForOrder($order);

    expect(fn () => app(PipelineDispatcher::class)->retry($order, $this->admin))->toThrow(LogicException::class);
});

it('skips a failed optional stage and continues', function () {
    $order = $this->paidOrder();
    FakeProvider::queue('editorial', LlmException::client(400, 'Bad request'));

    $job = app(PipelineDispatcher::class)->startForOrder($order);
    expect($job->fresh()->current_stage)->toBe(PipelineStage::Editorial)
        ->and($job->fresh()->status)->toBe(AiJobStatus::Failed);

    app(PipelineDispatcher::class)->skipStage($order->fresh(), PipelineStage::Editorial, $this->admin, 'Draft is fine as written');

    $job->refresh();
    expect($job->status)->toBe(AiJobStatus::Completed)
        ->and($job->steps()->where('stage', 'editorial')->where('status', StepStatus::Skipped->value)->value('error_message'))->toContain('Draft is fine as written')
        ->and(AuditLog::query()->where('action', 'ai.stage_skipped')->exists())->toBeTrue()
        ->and($this->engine['delivered'])->toHaveCount(1);
});

it('never skips rendering, file QA or delivery, nor a stage that is not current', function () {
    recordingTrigger();
    $order = $this->paidOrder();
    app(PipelineDispatcher::class)->startForOrder($order);

    expect(fn () => app(PipelineDispatcher::class)->skipStage($order, PipelineStage::Rendering, $this->admin, 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(PipelineDispatcher::class)->skipStage($order, PipelineStage::Delivery, $this->admin, 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(PipelineDispatcher::class)->skipStage($order, PipelineStage::Writing, $this->admin, 'x'))->toThrow(InvalidArgumentException::class);
});

it('cancels processing and hands a mid-pipeline order to a person', function () {
    recordingTrigger();
    $order = $this->paidOrder();
    $job = app(PipelineDispatcher::class)->startForOrder($order);
    app(OrderStateMachine::class)->transition($order, OrderStatus::Researching);

    app(PipelineDispatcher::class)->cancel($order, $this->admin, 'Customer asked to stop');

    expect($job->fresh()->status)->toBe(AiJobStatus::Cancelled)
        ->and($order->fresh()->status)->toBe(OrderStatus::ManualReview)
        ->and(app(PipelineWorker::class)->drive($job->id))->toBe(PipelineWorker::BUSY)
        ->and(AuditLog::query()->where('action', 'ai.pipeline_cancelled')->exists())->toBeTrue();
});

it('leaves a refunded order as it is when the refund cancels processing', function () {
    recordingTrigger();
    $order = $this->paidOrder();
    $job = app(PipelineDispatcher::class)->startForOrder($order);
    app(OrderStateMachine::class)->transition($order, OrderStatus::Refunded);

    app(PipelineDispatcher::class)->cancel($order, null, 'Order refunded');

    expect($job->fresh()->status)->toBe(AiJobStatus::Cancelled)
        ->and($order->fresh()->status)->toBe(OrderStatus::Refunded);
});

it('stops a running job when the order is refunded', function () {
    recordingTrigger();
    $order = $this->paidOrder();
    $job = app(PipelineDispatcher::class)->startForOrder($order);
    app(OrderStateMachine::class)->transition($order, OrderStatus::Refunded);

    app(PipelineWorker::class)->drive($job->id);

    expect($job->fresh()->status)->toBe(AiJobStatus::Cancelled)
        ->and($job->steps()->count())->toBe(0);
});

it('regenerates with a new numbered job and cancels the previous run', function () {
    $order = $this->paidOrder();
    $first = app(PipelineDispatcher::class)->startForOrder($order);

    $trigger = recordingTrigger();
    $regen = app(PipelineDispatcher::class)->regenerate($order->fresh(), $this->admin);
    $again = app(PipelineDispatcher::class)->regenerate($order->fresh(), $this->admin);

    expect($regen->kind)->toBe(AiJob::KIND_REGENERATION)
        ->and($regen->dedupe_key)->toBe("order:{$order->id}:regen:1")
        ->and($again->dedupe_key)->toBe("order:{$order->id}:regen:2")
        ->and($regen->fresh()->status)->toBe(AiJobStatus::Cancelled)
        ->and($first->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and($trigger->kicked)->toBe([$regen->id, $again->id]);

    app(PipelineWorker::class)->drive($again->id);
    expect($again->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and($this->engine['delivered'])->toHaveCount(2);
});

it('kicks only due, unleased jobs from the status page', function () {
    $trigger = recordingTrigger();
    $order = $this->paidOrder();
    $job = app(PipelineDispatcher::class)->startForOrder($order);
    $dispatcher = app(PipelineDispatcher::class);

    expect($dispatcher->kickIfDue($order))->toBeTrue();

    $job->forceFill(['leased_until' => now()->addMinutes(5)])->save();
    expect($dispatcher->kickIfDue($order))->toBeFalse();

    $job->forceFill(['leased_until' => null, 'next_run_at' => now()->addMinute()])->save();
    expect($dispatcher->kickIfDue($order))->toBeFalse();

    $job->forceFill(['next_run_at' => now()->subSecond()])->save();
    $dispatcher->pause($order, null, 'hold');
    expect($dispatcher->kickIfDue($order->fresh()))->toBeFalse()
        ->and($trigger->kicked)->toHaveCount(2);
});

it('continues due jobs from the heartbeat through the signed loopback route', function () {
    Http::fake(['*/internal/pipeline/*' => Http::response('', 202)]);

    recordingTrigger();
    $orderA = $this->paidOrder();
    $jobA = app(PipelineDispatcher::class)->startForOrder($orderA);
    app()->forgetInstance(PipelineTrigger::class);

    $triggered = app(PipelineDispatcher::class)->tickDue(3);

    expect($triggered)->toBe(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/internal/pipeline/'.$jobA->uuid)
        && $request->hasHeader('X-Statementra-Trigger'));
});

it('falls back to running one job in-process when the loopback is unavailable', function () {
    Http::fake(['*/internal/pipeline/*' => Http::response('', 500)]);

    recordingTrigger();
    $job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());
    app()->forgetInstance(PipelineTrigger::class);

    expect(app(PipelineDispatcher::class)->tickDue(3))->toBe(1)
        ->and($job->fresh()->status)->toBe(AiJobStatus::Completed);
});

it('marks a payment that was never verified as not fulfillable', function () {
    $order = $this->paidOrder();
    $order->successfulPayment()->first()->forceFill(['status' => PaymentRecordStatus::Initialized, 'verified_at' => null])->save();

    expect(fn () => app(PipelineDispatcher::class)->startForOrder($order->fresh()))->toThrow(FulfillmentDenied::class);
});
