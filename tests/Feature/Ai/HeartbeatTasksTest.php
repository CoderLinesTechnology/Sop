<?php

use App\Domain\Ai\Pipeline\PipelineTrigger;
use App\Domain\Ai\Pipeline\PipelineWorker;
use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Ai\Tasks\AdvanceDuePipelines;
use App\Domain\Ai\Tasks\NotifyDelayedOrders;
use App\Domain\Ai\Tasks\RecoverStalledPipelines;
use App\Domain\Notifications\AdminNotifier;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\AiJobStatus;
use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Models\AiJob;
use App\Models\AiJobStep;
use App\Models\EmailMessage;
use App\Models\InformationRequest;
use App\Models\Order;
use Mockery\MockInterface;
use Tests\Feature\Ai\Support\PipelineFixtures;
use Tests\Feature\Ai\Support\RecordingTrigger;

uses(PipelineFixtures::class);

beforeEach(function () {
    $this->setUpPipeline();
    $this->trigger = new RecordingTrigger;
    app()->instance(PipelineTrigger::class, $this->trigger);
});

/** A job whose PHP process died while running $stage (lease taken, attempt left "running"). */
function crashedJob(Order $order, PipelineStage $stage = PipelineStage::Ingestion): AiJob
{
    $job = app(PipelineDispatcher::class)->startForOrder($order);
    expect(app(PipelineWorker::class)->claim($job->id))->not->toBeNull();

    AiJobStep::query()->create(['ai_job_id' => $job->id, 'stage' => $stage, 'sequence' => 1, 'attempt' => 1, 'status' => StepStatus::Running, 'started_at' => now()]);
    AiJob::query()->whereKey($job->id)->update(['status' => AiJobStatus::Running->value, 'current_stage' => $stage->value]);

    return $job->refresh();
}

it('turns an attempt left running by a dead process into a failed attempt and retries it later', function () {
    $job = crashedJob($this->paidOrder());

    // The lease is still valid: nothing to recover yet.
    (new RecoverStalledPipelines(app(PipelineWorker::class), $this->trigger))();
    expect(AiJobStep::query()->where('ai_job_id', $job->id)->value('status'))->toBe(StepStatus::Running);

    $this->travel(PipelineWorker::LEASE_MINUTES + 1)->minutes();
    app(RecoverStalledPipelines::class)();

    $job->refresh();
    expect(AiJobStep::query()->where('ai_job_id', $job->id)->value('status'))->toBe(StepStatus::Failed)
        ->and(AiJobStep::query()->where('ai_job_id', $job->id)->value('error_code'))->toBe('interrupted')
        ->and($job->status)->toBe(AiJobStatus::Running)
        ->and($job->leased_until)->toBeNull()
        ->and($job->next_run_at->diffInSeconds(now(), true))->toBeBetween(29, 31);

    // Once due, the next trigger runs a fresh attempt to completion.
    $this->travel(31)->seconds();
    app(PipelineWorker::class)->drive($job->id);

    expect($job->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and(AiJobStep::query()->where('ai_job_id', $job->id)->where('stage', 'ingestion')->orderBy('id')->pluck('attempt')->all())->toBe([1, 2]);
});

it('sends the order to manual review when attempts keep being interrupted', function () {
    $this->mock(AdminNotifier::class, fn (MockInterface $mock) => $mock->shouldReceive('aiJobFailed')->once()->withArgs(fn ($order, $reason) => str_contains($reason, 'interrupted')));
    $order = $this->paidOrder();
    $job = crashedJob($order);
    app(OrderStateMachine::class)->transition($order, OrderStatus::Researching);

    for ($i = 1; $i <= 3; $i++) {
        $this->travel(PipelineWorker::LEASE_MINUTES + 10)->minutes();
        app(RecoverStalledPipelines::class)();

        if ($i < 3) {
            // A new attempt starts and dies again.
            AiJob::query()->whereKey($job->id)->update(['leased_until' => now()->addMinutes(PipelineWorker::LEASE_MINUTES), 'lease_token' => 'dead-process']);
            AiJobStep::query()->create(['ai_job_id' => $job->id, 'stage' => PipelineStage::Ingestion, 'sequence' => 1, 'attempt' => $i + 1, 'status' => StepStatus::Running, 'started_at' => now()]);
        }
    }

    expect($job->fresh()->status)->toBe(AiJobStatus::Failed)
        ->and($job->fresh()->next_run_at)->toBeNull()
        ->and($order->fresh()->status)->toBe(OrderStatus::ManualReview);
});

it('continues overdue jobs that nothing has picked up', function () {
    $job = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());
    AiJob::query()->whereKey($job->id)->update(['next_run_at' => now()->subMinutes(11)]);

    app(RecoverStalledPipelines::class)();

    expect($this->trigger->continued)->toBe([$job->id]);
});

it('advances due pipelines from the heartbeat without running stages itself', function () {
    $due = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());
    $later = app(PipelineDispatcher::class)->startForOrder($this->paidOrder());
    AiJob::query()->whereKey($later->id)->update(['next_run_at' => now()->addMinutes(5)]);

    app(AdvanceDuePipelines::class)();

    expect($this->trigger->continued)->toBe([$due->id])
        ->and($due->steps()->count())->toBe(0);
});

it('emails one delay notice for an order past its delivery window and alerts admins', function () {
    $this->mock(AdminNotifier::class, fn (MockInterface $mock) => $mock->shouldReceive('aiJobSlow')->once()->withArgs(fn (Order $order, int $minutes) => $minutes === 45));

    $order = $this->paidOrder();
    app(OrderStateMachine::class)->transition($order, OrderStatus::Researching);
    $order->forceFill(['fulfillment_started_at' => now()->subMinutes(45)])->save();

    app(NotifyDelayedOrders::class)();
    app(NotifyDelayedOrders::class)();

    expect(EmailMessage::query()->where('order_id', $order->id)->where('template_key', 'processing_delay')->count())->toBe(1)
        ->and($order->fresh()->delay_notified_at)->not->toBeNull();
});

it('does not send delay notices inside the window, while waiting for the customer, or right after their answer', function () {
    $this->mock(AdminNotifier::class, fn (MockInterface $mock) => $mock->shouldNotReceive('aiJobSlow'));

    $onTime = $this->paidOrder();
    app(OrderStateMachine::class)->transition($onTime, OrderStatus::Writing, force: true);
    $onTime->forceFill(['fulfillment_started_at' => now()->subMinutes(25)])->save();

    $waiting = $this->paidOrder();
    app(OrderStateMachine::class)->transition($waiting, OrderStatus::Researching);
    app(OrderStateMachine::class)->transition($waiting, OrderStatus::NeedsInformation);
    $waiting->forceFill(['fulfillment_started_at' => now()->subHours(3)])->save();

    $answered = $this->paidOrder();
    app(OrderStateMachine::class)->transition($answered, OrderStatus::Researching);
    $answered->forceFill(['fulfillment_started_at' => now()->subHours(3)])->save();
    InformationRequest::query()->create(['order_id' => $answered->id, 'questions' => [['key' => 'q1', 'question' => 'Background?', 'why' => '']], 'status' => 'answered', 'requested_at' => now()->subHours(2), 'answered_at' => now()->subMinutes(5)]);

    app(NotifyDelayedOrders::class)();

    expect(EmailMessage::query()->where('template_key', 'processing_delay')->count())->toBe(0);
});
