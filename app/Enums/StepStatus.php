<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StepStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Superseded = 'superseded';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Running => 'info',
            self::Failed => 'danger',
            self::Pending => 'warning',
            self::Skipped, self::Superseded => 'gray',
        };
    }
}
