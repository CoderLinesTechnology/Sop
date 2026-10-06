<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Lets a dynamic form field feed a core order attribute. A service's form can
 * use any keys and labels; mapping a field here is what makes its value
 * available to the requirements engine, file naming, emails and admin search.
 */
enum FieldMapping: string implements HasLabel
{
    case CustomerName = 'customer_name';
    case Email = 'email';
    case Phone = 'customer_phone';
    case Institution = 'institution';
    case Programme = 'programme';
    case DegreeLevel = 'degree_level';
    case Country = 'country_code';
    case Intake = 'intake';
    case Deadline = 'deadline';
    case EssayPrompt = 'essay_prompt';
    case WordLimit = 'word_limit';

    public function getLabel(): string
    {
        return match ($this) {
            self::CustomerName => 'Applicant full name',
            self::Email => 'Delivery email',
            self::Phone => 'Phone number',
            self::Institution => 'University / institution',
            self::Programme => 'Programme / course',
            self::DegreeLevel => 'Degree level',
            self::Country => 'Destination country',
            self::Intake => 'Intake / year',
            self::Deadline => 'Application deadline',
            self::EssayPrompt => 'Essay prompt / question',
            self::WordLimit => 'Stated word limit',
        };
    }

    /** The orders table column this mapping writes to. */
    public function column(): string
    {
        return $this->value;
    }
}
