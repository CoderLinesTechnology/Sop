<?php

use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Orders\InformationRequestService;
use App\Enums\AiJobStatus;
use App\Enums\OrderStatus;
use App\Models\AdminUser;
use App\Models\AiJobStep;
use App\Models\Applicant;
use App\Models\InformationRequest;
use App\Models\Order;
use Tests\Feature\Ai\Support\PipelineFixtures;

uses(PipelineFixtures::class);

beforeEach(fn () => $this->setUpPipeline());

function orderAskedForMore(object $test): array
{
    $order = $test->paidOrder(answers: [
        ['full_name', 'Full name', 'text', 'details', 'Kofi Boateng'],
        ['goals', 'Your goals', 'textarea', 'story', 'I want to work on renewable energy policy in Ghana.'],
    ]);
    $job = app(PipelineDispatcher::class)->startForOrder($order);

    return [$order, $job, InformationRequest::query()->where('order_id', $order->id)->sole()];
}

it('adds follow-up answers to the profile without another ingestion call', function () {
    [$order, $job, $request] = orderAskedForMore($this);

    app(InformationRequestService::class)->answer($request, ['q1' => 'I studied Electrical Engineering at KNUST and installed solar mini-grids for two years.']);

    $facts = collect(Applicant::query()->where('order_id', $order->id)->firstOrFail()->profile['facts']);
    $fact = $facts->firstWhere('source_ref', 'followup_'.$request->id.'_q1');

    expect($fact)->not->toBeNull()
        ->and($fact['source_type'])->toBe('answer')
        ->and($fact['evidence_quote'])->toContain('solar mini-grids')
        ->and($fact['quote_verified'])->toBeTrue()
        ->and($fact['category'])->toBe('work_history')
        ->and($facts->where('id', $fact['id']))->toHaveCount(1)
        ->and($job->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and(AiJobStep::query()->where('ai_job_id', $job->id)->where('stage', 'ingestion')->count())->toBe(1)
        ->and(AiJobStep::query()->where('ai_job_id', $job->id)->where('stage', 'analysis')->count())->toBe(2);
});

it('reads everything again when there is no applicant profile to add to', function () {
    [$order, $job, $request] = orderAskedForMore($this);
    Applicant::query()->where('order_id', $order->id)->delete();

    app(InformationRequestService::class)->answer($request, ['q1' => 'I studied Electrical Engineering at KNUST and installed solar mini-grids for two years.']);

    expect($job->fresh()->status)->toBe(AiJobStatus::Completed)
        ->and(AiJobStep::query()->where('ai_job_id', $job->id)->where('stage', 'ingestion')->count())->toBe(2);
});

it('does not ask the customer again when the document is regenerated', function () {
    [$order, $job, $request] = orderAskedForMore($this);
    app(InformationRequestService::class)->answer($request, ['q1' => 'I studied Electrical Engineering at KNUST and installed solar mini-grids for two years.']);
    expect($job->fresh()->status)->toBe(AiJobStatus::Completed);

    // As in production: the order sits in manual review and an administrator regenerates it.
    Order::query()->whereKey($order->id)->update(['status' => OrderStatus::ManualReview->value]);
    $again = app(PipelineDispatcher::class)->regenerate($order->fresh(), AdminUser::factory()->create());

    expect(InformationRequest::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and($again->fresh()->status)->not->toBe(AiJobStatus::WaitingForCustomer);
});
