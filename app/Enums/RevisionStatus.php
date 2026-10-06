<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RevisionStatus: string implements HasColor, HasLabel
{
    case AwaitingPayment = 'awaiting_payment';
    case Requested = 'requested';
    case Processing = 'processing';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'Awaiting payment',
            self::Requested => 'Requested',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Processing => 'info',
            self::Requested, self::AwaitingPayment => 'warning',
            self::Rejected => 'danger',
            self::Cancelled => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::AwaitingPayment, self::Requested, self::Processing], true);
    }
}
