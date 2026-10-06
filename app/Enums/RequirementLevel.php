<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RequirementLevel: string implements HasLabel
{
    case Required = 'required';
    case Recommended = 'recommended';
    case Optional = 'optional';

    public function getLabel(): string
    {
        return match ($this) {
            self::Required => 'Required',
            self::Recommended => 'Recommended',
            self::Optional => 'Optional',
        };
    }
}
