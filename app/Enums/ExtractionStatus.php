<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Text extraction state of an uploaded file. Images and scanned PDFs have no
 * text layer; they are marked "needs_vision" and read by the model directly.
 */
enum ExtractionStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Extracted = 'extracted';
    case NeedsVision = 'needs_vision';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Extracted => 'Text extracted',
            self::NeedsVision => 'Read visually',
            self::Failed => 'Failed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Extracted => 'success',
            self::NeedsVision => 'info',
            self::Pending => 'warning',
            self::Failed => 'danger',
        };
    }
}
