<?php

namespace App\Events;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

final class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
        public readonly string $actorType,
    ) {}
}
