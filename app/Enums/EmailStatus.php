<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EmailStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Deferred = 'deferred';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sent, self::Delivered => 'success',
            self::Queued, self::Sending, self::Deferred => 'warning',
            self::Bounced, self::Complained, self::Failed => 'danger',
        };
    }

    /** Whether the provider accepted the message for delivery. */
    public function wasAccepted(): bool
    {
        return in_array($this, [self::Sent, self::Delivered, self::Deferred], true);
    }
}
