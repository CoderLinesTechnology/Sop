<?php

use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Orders\OrderAccess;
use App\Enums\OrderStatus;
use App\Models\InformationRequest;
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
