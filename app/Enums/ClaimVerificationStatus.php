<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ClaimVerificationStatus: string implements HasColor, HasLabel
{
    case Verified = 'verified';
    case PartiallyVerified = 'partially_verified';
    case Unverified = 'unverified';
    case Conflicting = 'conflicting';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Verified => 'Verified',
            self::PartiallyVerified => 'Partially verified',
            self::Unverified => 'Unverified',
            self::Conflicting => 'Conflicting',
            self::Rejected => 'Rejected',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Verified => 'success',
            self::PartiallyVerified => 'info',
            self::Unverified => 'warning',
            self::Conflicting, self::Rejected => 'danger',
        };
    }
}
