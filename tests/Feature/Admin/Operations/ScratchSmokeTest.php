<?php

use App\Enums\AdminRole;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;

it('renders order list and view', function () {
    actingAsAdmin(AdminRole::SuperAdmin);
    $order = Order::factory()->paid(OrderStatus::Writing)->create();

    $this->get(OrderResource::getUrl('index'))->assertOk()->assertSee($order->reference);
    $this->get(OrderResource::getUrl('view', ['record' => $order]))->assertOk()->assertSee($order->reference);
});
