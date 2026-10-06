<?php

namespace App\Domain\Orders;

use RuntimeException;

final class FulfillmentDenied extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
