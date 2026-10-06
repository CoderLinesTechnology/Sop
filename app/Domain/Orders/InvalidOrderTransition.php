<?php

namespace App\Domain\Orders;

use App\Enums\OrderStatus;
use RuntimeException;

final class InvalidOrderTransition extends RuntimeException
{
    public static function notAllowed(OrderStatus $from, OrderStatus $to): self
    {
        return new self("Order cannot move from {$from->value} to {$to->value}.");
    }

    public static function stale(OrderStatus $expected, ?OrderStatus $actual, OrderStatus $to): self
    {
        $actualValue = $actual?->value ?? 'unknown';

        return new self("Order status changed concurrently (expected {$expected->value}, found {$actualValue}) while moving to {$to->value}.");
    }
}
