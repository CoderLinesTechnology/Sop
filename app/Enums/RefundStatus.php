<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RefundStatus: string implements HasColor, HasLabel
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Processing = 'processing';
    case Processed = 'processed';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Processed => 'success',
            self::Approved, self::Processing => 'info',
            self::Requested => 'warning',
            self::Rejected, self::Failed => 'danger',
        };
    }

    /** Refunds that still count against the refundable balance. */
    public function isCommitted(): bool
    {
        return in_array($this, [self::Approved, self::Processing, self::Processed], true);
    }
}
