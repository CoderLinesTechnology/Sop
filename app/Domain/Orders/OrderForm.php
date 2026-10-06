<?php

namespace App\Domain\Orders;

use App\Enums\FieldMapping;
use App\Enums\FieldSection;
use App\Enums\FieldType;
use App\Enums\RequirementLevel;
use App\Models\Service;
use App\Models\ServiceField;
use App\Support\Countries;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Turns a service's administrator-configured fields into the order form:
 * grouping for display, server-side validation rules and normalised answers.
 *
 * Adaptive rules:
 *  - a required field with `optional_when_upload = cv` becomes optional when
 *    the customer uploads a file into the "cv" slot;
 *  - a field with `show_when` is only validated (and stored) when its
 *    condition is met.
 */
final class OrderForm
{
    /** @var Collection<int, ServiceField> */
    public readonly Collection $fields;

    public function __construct(public readonly Service $service)
    {
        $this->fields = $service->relationLoaded('activeFields')
            ? $service->activeFields
            : $service->activeFields()->get();
    }

    /** @return Collection<int, ServiceField> */
    public function fileFields(): Collection
    {
        return $this->fields->filter(fn (ServiceField $f) => $f->isFile())->values();
    }

    /** @return Collection<int, ServiceField> */
    public function inputFields(): Collection
    {
        return $this->fields->reject(fn (ServiceField $f) => $f->isFile())->values();
    }

    /**
     * Sections in display order with their (non-file) fields.
     *
     * @return array<string, array{section: FieldSection, fields: Collection<int, ServiceField>}>
     */
    public function sections(): array
    {
        $sections = [];
        foreach (FieldSection::cases() as $section) {
            $fields = $this->inputFields()->filter(fn (ServiceField $f) => $f->section === $section)->values();
            if ($fields->isNotEmpty()) {
                $sections[$section->value] = ['section' => $section, 'fields' => $fields];
            }
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $answers  raw answers keyed by field key
     * @param  list<string>  $uploadedSlots  file field keys that have at least one file
     * @return array<string, mixed>
     */
    public function rules(array $answers, array $uploadedSlots): array
    {
        $rules = [];

        foreach ($this->inputFields() as $field) {
            $name = 'answers.'.$field->key;

            if (! $this->isVisible($field, $answers)) {
                $rules[$name] = ['nullable'];

                continue;
            }

            $required = $field->requirement === RequirementLevel::Required
                && ! ($field->optional_when_upload && in_array($field->optional_when_upload, $uploadedSlots, true));

            $base = $required ? ['required'] : ['nullable'];
            $validation = (array) $field->validation;

            $rules[$name] = match ($field->type) {
                FieldType::Text => [...$base, 'string', 'max:'.min(1000, $field->maxLength()), ...$this->minLength($validation)],
                FieldType::Textarea => [...$base, 'string', 'max:'.min(10000, $field->maxLength()), ...$this->minLength($validation)],
                FieldType::Email => [...$base, 'string', 'email:rfc', 'max:190'],
                FieldType::Phone => [...$base, 'string', 'max:40', 'regex:/^[0-9 ()+\-.]{5,40}$/'],
                FieldType::Url => [...$base, 'string', 'max:500', 'url:https,http'],
                FieldType::Number => [...$base, 'integer', 'min:'.(int) ($validation['min'] ?? 0), 'max:'.(int) ($validation['max'] ?? 1000000)],
                FieldType::Date => [...$base, 'date', 'after:2000-01-01', 'before:2100-01-01'],
                FieldType::Select, FieldType::Radio => [...$base, 'string', Rule::in(array_keys($field->choices()))],
                FieldType::MultiSelect => [...$base, 'array', 'max:'.max(1, count($field->choices()))],
                FieldType::Checkbox => $required ? ['accepted'] : ['nullable', 'boolean'],
                FieldType::Country => [...$base, 'string', 'size:2', Rule::in(array_keys(Countries::all()))],
                FieldType::File => ['nullable'],
            };

            if ($field->type === FieldType::MultiSelect) {
                $rules[$name.'.*'] = ['string', Rule::in(array_keys($field->choices()))];
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributeNames(): array
    {
        return $this->inputFields()->mapWithKeys(fn (ServiceField $f) => ['answers.'.$f->key => strtolower($f->label)])->all();
    }

    /**
     * Normalised answers for storage and the order columns they map to.
     *
     * @param  array<string, mixed>  $answers  validated answers
     * @return array{answers: array<string, mixed>, mapped: array<string, mixed>}
     */
    public function normalize(array $answers): array
    {
        $clean = [];
        $mapped = [];

        foreach ($this->inputFields() as $field) {
            if (! $this->isVisible($field, $answers)) {
                continue;
            }

            $value = $answers[$field->key] ?? null;
            $value = match ($field->type) {
                FieldType::Checkbox => (bool) $value,
                FieldType::Number => $value === null || $value === '' ? null : (int) $value,
                FieldType::MultiSelect => array_values(array_filter(Arr::wrap($value), 'is_string')),
                FieldType::Country => $value ? strtoupper((string) $value) : null,
                FieldType::Email => $value ? strtolower(trim((string) $value)) : null,
                default => $this->cleanString($value),
            };

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $clean[$field->key] = $value;

            if ($field->maps_to instanceof FieldMapping) {
                $mapped[$field->maps_to->column()] = is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return ['answers' => $clean, 'mapped' => $mapped];
    }

    public function isVisible(ServiceField $field, array $answers): bool
    {
        $condition = (array) $field->show_when;
        if (empty($condition['field'])) {
            return true;
        }

        $actual = $answers[$condition['field']] ?? null;
        $expected = $condition['equals'] ?? null;

        return is_array($actual) ? in_array($expected, $actual, true) : (string) $actual === (string) $expected;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $value = preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? '';

        return trim($value) === '' ? null : trim($value);
    }

    /** @return list<string> */
    private function minLength(array $validation): array
    {
        return isset($validation['min_length']) ? ['min:'.(int) $validation['min_length']] : [];
    }
}
