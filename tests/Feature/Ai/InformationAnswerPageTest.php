<?php

use App\Domain\Ai\FollowUpFacts;
use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Orders\InformationRequestService;
use App\Domain\Orders\OrderAccess;
use App\Enums\OrderStatus;
use App\Models\AiJobStep;
use App\Models\InformationRequest;
use Mockery\MockInterface;
use Tests\Feature\Ai\Support\PipelineFixtures;

uses(PipelineFixtures::class);

beforeEach(fn () => $this->setUpPipeline());

function orderWaitingForAnswer(object $test): array
{
    $order = $test->paidOrder(answers: [
        ['full_name', 'Full name', 'text', 'details', 'Kofi Boateng'],
        ['goals', 'Your goals', 'textarea', 'story', 'I want to work on renewable energy policy in Ghana.'],
    ]);
    app(PipelineDispatcher::class)->startForOrder($order);
    expect($order->fresh()->status)->toBe(OrderStatus::NeedsInformation);

    $test->get(OrderAccess::statusUrl($order->fresh()))->assertRedirect(); // grants this browser session

    return [$order->fresh(), InformationRequest::query()->where('order_id', $order->id)->sole()];
}

it('shows the order page, not an error, when the answer form is sent twice', function () {
    [$order, $request] = orderWaitingForAnswer($this);
    $answer = ['answers' => ['q1' => 'I studied Electrical Engineering at KNUST and installed solar mini-grids for two years.']];

    $this->post(route('orders.information', $order->public_id), $answer)
        ->assertRedirect(route('orders.show', $order->public_id))
        ->assertSessionHas('status', fn ($status) => str_contains($status, "we've received your answer"));

    $this->post(route('orders.information', $order->public_id), $answer)
        ->assertRedirect(route('orders.show', $order->public_id))
        ->assertSessionHas('status', fn ($status) => str_contains($status, 'already received your answer'));

    expect($request->fresh()->status)->toBe('answered')
        ->and($request->fresh()->answers)->toBe($answer['answers']);
});

it('sends a reload of the answer address to the order page', function () {
    [$order] = orderWaitingForAnswer($this);

    $this->get('/o/'.$order->public_id.'/information')->assertRedirect(route('orders.show', $order->public_id));
});

it('still requires access to the order for the answer address', function () {
    [$order] = orderWaitingForAnswer($this);
    $this->flushSession();

    $this->get('/o/'.$order->public_id.'/information')->assertForbidden();
    $this->post(route('orders.information', $order->public_id), ['answers' => ['q1' => 'x']])->assertForbidden();
});

it('offers answer starters under each follow-up question', function () {
    [$order] = orderWaitingForAnswer($this);

    $this->get(route('orders.show', $order->public_id))
        ->assertOk()
        ->assertSee('data-starter="For example, …"', false)
        ->assertSee('data-starter-target="f-answers-q1"', false);
});

it('suggests answers drafted from the customer\'s own details under each question', function () {
    [$order, $request] = orderWaitingForAnswer($this);

    expect($request->questions[0]['suggestions'])->not->toBeEmpty();

    $this->get(route('orders.show', $order->public_id))
        ->assertOk()
        ->assertSee('Suggested answers from your details')
        ->assertSee('data-starter="'.e($request->questions[0]['suggestions'][0]).'"', false)
        ->assertSee('data-draft-key="followup:'.$order->public_id.':', false);
});

it('keeps the saved answer and shows success when restarting the work fails afterwards', function () {
    [$order, $request] = orderWaitingForAnswer($this);
    $this->mock(PipelineDispatcher::class, fn (MockInterface $mock) => $mock->shouldReceive('resume')->andThrow(new RuntimeException('process start failed')));

    $this->post(route('orders.information', $order->public_id), ['answers' => ['q1' => 'I studied Electrical Engineering at KNUST.']])
        ->assertRedirect(route('orders.show', $order->public_id))
        ->assertSessionHas('status');

    expect($request->fresh()->status)->toBe('answered');
});

it('keeps what the customer typed, with a gentle message, when the answer cannot be saved', function () {
    [$order] = orderWaitingForAnswer($this);
    $this->mock(InformationRequestService::class, fn (MockInterface $mock) => $mock->shouldReceive('answer')->andThrow(new RuntimeException('database unavailable')));

    $this->from(route('orders.show', $order->public_id))
        ->post(route('orders.information', $order->public_id), ['answers' => ['q1' => 'My project on solar pumps.']])
        ->assertRedirect(route('orders.show', $order->public_id))
        ->assertSessionHasErrors('answers')
        ->assertSessionHasInput('answers.q1', 'My project on solar pumps.');
});

it('still saves the answer and re-reads the material when it cannot be added to the profile', function () {
    [$order, $request] = orderWaitingForAnswer($this);
    $job = $order->latestAiJob;
    $this->mock(FollowUpFacts::class, fn (MockInterface $mock) => $mock->shouldReceive('merge')->andThrow(new RuntimeException('profile locked')));

    $this->post(route('orders.information', $order->public_id), ['answers' => ['q1' => 'I studied Electrical Engineering at KNUST.']])
        ->assertRedirect(route('orders.show', $order->public_id));

    expect($request->fresh()->status)->toBe('answered')
        ->and(AiJobStep::query()->where('ai_job_id', $job->id)->where('stage', 'ingestion')->count())->toBe(2);
});

it('explains instead of showing "session expired" when the form was left open too long', function () {
    [$order] = orderWaitingForAnswer($this);

    // Outside the testing environment the CSRF check runs, as it does in production.
    app()->instance('env', 'local');
    try {
        $response = $this->post(route('orders.information', $order->public_id), ['answers' => ['q1' => 'An answer'], '_token' => 'expired']);
    } finally {
        app()->instance('env', 'testing');
    }

    $response->assertStatus(419)
        ->assertSee('saved on this device, but not sent yet')
        ->assertSee(route('orders.show', $order->public_id), false);
});
