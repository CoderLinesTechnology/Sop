<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Order-level payment status. Only PaymentConfirmationService may set PAID. */
enum PaymentStatus: string implements HasColor, HasLabel
{
    case Unpaid = 'UNPAID';
    case Pending = 'PENDING';
    case Paid = 'PAID';
    case Failed = 'FAILED';
    case Refunded = 'REFUNDED';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially refunded',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Pending => 'warning',
            self::Failed => 'danger',
            default => 'gray',
        };
    }
}
