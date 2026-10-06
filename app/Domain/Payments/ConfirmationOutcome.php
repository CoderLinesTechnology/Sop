<?php

namespace App\Domain\Payments;

enum ConfirmationOutcome: string
{
    case Confirmed = 'confirmed';
    case AlreadyConfirmed = 'already_confirmed';
    case Pending = 'pending';
    case Failed = 'failed';
    case Mismatch = 'mismatch';
    case NotFound = 'not_found';

    public function isPaid(): bool
    {
        return $this === self::Confirmed || $this === self::AlreadyConfirmed;
    }
}
