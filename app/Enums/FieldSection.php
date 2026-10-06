<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Groups of questions on the order form, rendered in this order. File fields
 * are always rendered in the "Upload what you have" group regardless of section.
 */
enum FieldSection: string implements HasLabel
{
    case Details = 'details';
    case Application = 'application';
    case Story = 'story';
    case Additional = 'additional';

    public function getLabel(): string
    {
        return match ($this) {
            self::Details => 'Your details',
            self::Application => 'Application details',
            self::Story => 'Your story',
            self::Additional => 'Anything else we should know?',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Details => 'Tell us a little about yourself.',
            self::Application => 'What are you applying for?',
            self::Story => 'A few short answers help us capture your real motivation. Answer what you can.',
            self::Additional => 'Add any extra information, special instructions or notes for your application.',
        };
    }

    /** Heading used on the review page. */
    public function reviewTitle(): string
    {
        return match ($this) {
            self::Details => 'Personal Information',
            self::Application => 'Application Details',
            self::Story, self::Additional => 'Additional Information',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Details => 'user',
            self::Application => 'graduation-cap',
            self::Story, self::Additional => 'message',
        };
    }
}
