<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Status of an individual payment attempt (one Paystack transaction). */
enum PaymentRecordStatus: string implements HasColor, HasLabel
{
    case Initialized = 'initialized';
    case Success = 'success';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
    case Mismatch = 'mismatch';
    case Reversed = 'reversed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Waived = 'waived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Initialized => 'Initialized',
            self::Success => 'Successful',
            self::Failed => 'Failed',
            self::Abandoned => 'Abandoned',
            self::Mismatch => 'Verification mismatch',
            self::Reversed => 'Reversed',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially refunded',
            self::Waived => 'Waived (fully discounted)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Success, self::Waived => 'success',
            self::Initialized => 'warning',
            self::Failed, self::Mismatch, self::Reversed => 'danger',
            default => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Initialized;
    }
}
