<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Input types available to administrators when building a service's order form. */
enum FieldType: string implements HasLabel
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Email = 'email';
    case Phone = 'phone';
    case Url = 'url';
    case Number = 'number';
    case Date = 'date';
    case Select = 'select';
    case MultiSelect = 'multiselect';
    case Radio = 'radio';
    case Checkbox = 'checkbox';
    case Country = 'country';
    case File = 'file';

    public function getLabel(): string
    {
        return match ($this) {
            self::Text => 'Short text',
            self::Textarea => 'Long text',
            self::Email => 'Email',
            self::Phone => 'Phone number',
            self::Url => 'Web address',
            self::Number => 'Number',
            self::Date => 'Date',
            self::Select => 'Dropdown',
            self::MultiSelect => 'Multiple choice (checkboxes)',
            self::Radio => 'Single choice (radio)',
            self::Checkbox => 'Yes / no checkbox',
            self::Country => 'Country',
            self::File => 'File upload',
        };
    }

    public function hasOptions(): bool
    {
        return in_array($this, [self::Select, self::MultiSelect, self::Radio], true);
    }
}
