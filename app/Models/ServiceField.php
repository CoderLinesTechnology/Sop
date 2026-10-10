<?php

namespace App\Models;

use App\Enums\FieldMapping;
use App\Enums\FieldSection;
use App\Enums\FieldType;
use App\Enums\RequirementLevel;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question or upload slot on a service's order form.
 *
 * options (by type):
 *  - select / multiselect / radio: {"choices": [{"value": "msc", "label": "Master's"}]}
 *  - file: {"accept": ["pdf","docx"], "max_files": 3, "purpose": "cv"}
 *  - textarea: {"starters": ["I first became interested in … when …"]}
 *    clickable sentence starters shown under the question (no AI involved)
 * validation: {"min_length": 10, "max_length": 3000, "min": 0, "max": 5000}
 * show_when: {"field": "degree_level", "equals": "phd"}
 * optional_when_upload: key of a file field; when a file is uploaded there,
 *   this question becomes optional (e.g. background questions vs. a CV).
 */
#[Unguarded]
class ServiceField extends Model
{
    public const UPLOAD_PURPOSES = [
        'cv' => 'CV / Resume',
        'requirements' => 'Programme or scholarship requirements',
        'previous_statement' => 'Previous / existing statement',
        'transcript' => 'Transcript / academic documents',
        'writing_sample' => 'Writing sample',
        'other' => 'Other documents',
    ];

    /** Answer starters per textarea question, and the longest starter kept. */
    public const MAX_STARTERS = 4;

    public const MAX_STARTER_LENGTH = 120;

    protected function casts(): array
    {
        return [
            'type' => FieldType::class,
            'section' => FieldSection::class,
            'requirement' => RequirementLevel::class,
            'maps_to' => FieldMapping::class,
            'options' => 'array',
            'validation' => 'array',
            'show_when' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function isFile(): bool
    {
        return $this->type === FieldType::File;
    }

    public function isRequired(): bool
    {
        return $this->requirement === RequirementLevel::Required;
    }

    /** @return list<string> sentence starters offered under a textarea question */
    public function starters(): array
    {
        if ($this->type !== FieldType::Textarea) {
            return [];
        }

        return array_values(array_slice(array_filter(
            array_map(fn ($starter) => is_string($starter) ? trim($starter) : '', (array) data_get($this->options, 'starters', [])),
            fn (string $starter) => $starter !== '',
        ), 0, self::MAX_STARTERS));
    }

    /** @return array<string, string> value => label */
    public function choices(): array
    {
        $choices = [];
        foreach ((array) data_get($this->options, 'choices', []) as $choice) {
            if (is_array($choice) && filled($choice['value'] ?? null)) {
                $choices[(string) $choice['value']] = (string) ($choice['label'] ?? $choice['value']);
            } elseif (is_string($choice) && $choice !== '') {
                $choices[$choice] = $choice;
            }
        }

        return $choices;
    }

    /** @return list<string> lowercase extensions */
    public function acceptedExtensions(): array
    {
        $global = Settings::allowedUploadExtensions();
        $accept = array_map('strtolower', (array) data_get($this->options, 'accept', []));

        return $accept ? array_values(array_intersect($accept, $global)) : $global;
    }

    public function maxFiles(): int
    {
        return max(1, min(10, (int) data_get($this->options, 'max_files', 3)));
    }

    public function uploadPurpose(): string
    {
        $purpose = (string) data_get($this->options, 'purpose', $this->key);

        return array_key_exists($purpose, self::UPLOAD_PURPOSES) ? $purpose : 'other';
    }

    public function maxLength(): int
    {
        $default = $this->type === FieldType::Textarea ? 5000 : 255;

        return (int) data_get($this->validation, 'max_length', $default);
    }
}
