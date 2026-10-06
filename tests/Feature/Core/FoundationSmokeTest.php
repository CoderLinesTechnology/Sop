<?php

use App\Domain\Orders\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Models\Order;

it('creates orders with unguessable identifiers', function () {
    $order = Order::factory()->create();

    expect($order->public_id)->toHaveLength(26)
        ->and($order->reference)->toMatch('/^ST-[0-9A-Z]{4}-[0-9A-Z]{4}$/')
        ->and($order->status)->toBe(OrderStatus::FormSubmitted);
});

it('records every status transition', function () {
    $order = Order::factory()->create();

    app(OrderStateMachine::class)->transition($order, OrderStatus::PaymentPending, 'customer');

    expect($order->fresh()->status)->toBe(OrderStatus::PaymentPending)
        ->and($order->statusHistories()->count())->toBe(1);
});

it('lets an admin with MFA reach the panel', function () {
    actingAsAdmin();

    $this->get('/admin')->assertOk();
});
