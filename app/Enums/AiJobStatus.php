<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AiJobStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Running = 'running';
    case WaitingForCustomer = 'waiting_for_customer';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case ManualReview = 'manual_review';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::WaitingForCustomer => 'Waiting for customer',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::ManualReview => 'Manual review',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Running, self::Queued => 'info',
            self::WaitingForCustomer, self::Paused, self::ManualReview => 'warning',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /** Whether a worker may pick this job up and run its next stage. */
    public function isRunnable(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }
}
