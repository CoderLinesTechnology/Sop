<?php

namespace App\Domain\Files;

use RuntimeException;

/** An upload failed validation; the message is safe to show the customer. */
final class UploadRejected extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }
}
