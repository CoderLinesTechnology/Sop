<?php

namespace App\Domain\Payments;

use RuntimeException;

/** Checkout could not start; the message is safe to show the customer. */
final class CheckoutException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'payment')
    {
        parent::__construct($message);
    }
}
