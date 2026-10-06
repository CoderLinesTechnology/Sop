<?php

namespace App\Filament\Support\Catalogue;

use App\Enums\FieldType;
use App\Models\ServiceField;

/**
 * Cleans a form-builder item before it is stored, so the JSON columns only
 * ever contain the shape documented on App\Models\ServiceField and settings
 * that do not apply to the chosen type are removed (e.g. choices left over
 * after a dropdown was turned into a text question).
 */
final class ServiceFieldNormalizer
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $type = ServiceFieldSchema::type($data['type'] ?? null) ?? FieldType::Text;
        $options = is_array($data['options'] ?? null) ? $data['options'] : [];

        $data['type'] = $type->value;
        $data['key'] = strtolower(trim((string) ($data['key'] ?? '')));
        $data['label'] = trim((string) ($data['label'] ?? ''));
        $data['options'] = self::options($type, $options);
        $data['validation'] = self::validation($type, is_array($data['validation'] ?? null) ? $data['validation'] : []);
        $data['show_when'] = self::showWhen($data['show_when'] ?? null);
        $data['maps_to'] = ServiceFieldSchema::mapping($data['maps_to'] ?? null)?->value;
        $data['requirement'] = ServiceFieldSchema::requirement($data['requirement'] ?? null)?->value ?? 'optional';
        $data['width'] = in_array($data['width'] ?? null, ['full', 'half'], true) ? $data['width'] : 'full';

        if ($type === FieldType::File || blank($data['optional_when_upload'] ?? null)) {
            $data['optional_when_upload'] = null;
        }

        foreach (['help_text', 'placeholder', 'ai_hint'] as $text) {
            if (array_key_exists($text, $data)) {
                $data[$text] = filled($data[$text]) ? trim((string) $data[$text]) : null;
            }
        }

        if (in_array($type, [FieldType::File, FieldType::Checkbox, FieldType::Radio, FieldType::MultiSelect], true)) {
            $data['placeholder'] = null;
        }

        return $data;
    }

    /** @return array<string, mixed>|null */
    private static function options(FieldType $type, array $options): ?array
    {
        if ($type->hasOptions()) {
            $choices = [];
            foreach ((array) ($options['choices'] ?? []) as $choice) {
                $value = is_array($choice) ? trim((string) ($choice['value'] ?? '')) : trim((string) $choice);
                if ($value === '') {
                    continue;
                }
                $label = is_array($choice) ? trim((string) ($choice['label'] ?? '')) : '';
                $choices[] = ['value' => $value, 'label' => $label !== '' ? $label : $value];
            }

            return ['choices' => $choices];
        }

        if ($type === FieldType::File) {
            $allowed = array_keys(ServiceFieldSchema::FILE_EXTENSIONS);
            $accept = array_values(array_intersect($allowed, array_map('strtolower', array_map('strval', (array) ($options['accept'] ?? [])))));
            $purpose = (string) ($options['purpose'] ?? 'other');

            return [
                'accept' => $accept ?: $allowed,
                'max_files' => max(1, min(10, (int) ($options['max_files'] ?? 1))),
                'purpose' => array_key_exists($purpose, ServiceField::UPLOAD_PURPOSES) ? $purpose : 'other',
            ];
        }

        return null;
    }

    /** @return array<string, int|float>|null */
    private static function validation(FieldType $type, array $validation): ?array
    {
        $allowed = match ($type) {
            FieldType::Text, FieldType::Textarea, FieldType::Email, FieldType::Phone, FieldType::Url => ['min_length', 'max_length'],
            FieldType::Number => ['min', 'max'],
            default => [],
        };

        $clean = [];
        foreach ($allowed as $rule) {
            $value = $validation[$rule] ?? null;
            if (is_numeric($value)) {
                $clean[$rule] = str_contains((string) $value, '.') ? (float) $value : (int) $value;
            }
        }

        return $clean ?: null;
    }

    /** @return array{field:string, equals:string}|null */
    private static function showWhen(mixed $showWhen): ?array
    {
        $field = is_array($showWhen) ? trim((string) ($showWhen['field'] ?? '')) : '';

        return $field === '' ? null : ['field' => $field, 'equals' => trim((string) ($showWhen['equals'] ?? ''))];
    }
}
