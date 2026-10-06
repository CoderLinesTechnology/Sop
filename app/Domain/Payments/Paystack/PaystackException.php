<?php

namespace App\Domain\Payments\Paystack;

use RuntimeException;

/** A Paystack API call failed. The message is for logs/admins, never customers. */
final class PaystackException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
