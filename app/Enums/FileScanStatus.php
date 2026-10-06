<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FileScanStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Skipped = 'skipped';
    case Error = 'error';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Clean => 'success',
            self::Infected, self::Error => 'danger',
            self::Pending => 'warning',
            self::Skipped => 'gray',
        };
    }

    /** Whether the file may be read by the pipeline. */
    public function isUsable(): bool
    {
        return in_array($this, [self::Clean, self::Skipped], true);
    }
}
