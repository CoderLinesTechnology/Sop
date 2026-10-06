<?php

namespace App\Filament\Support\Catalogue;

use App\Enums\FieldMapping;
use App\Enums\FieldType;
use App\Enums\RequirementLevel;
use App\Models\Service;
use App\Models\ServiceField;

/**
 * Sanity checks for a service's order form. An order needs a delivery email
 * and the applicant's name, so every service should have an active, required
 * question mapped to each. Problems are reported as warnings, not errors, so
 * a work-in-progress form can still be saved.
 */
final class ServiceFormChecks
{
    /** Mappings every order needs, with why. */
    private const ESSENTIAL = [
        'email' => 'the finished document is emailed to this address',
        'customer_name' => 'the document and emails are personalised with it',
    ];

    /** Answer types that fit each mapping best. */
    private const EXPECTED_TYPES = [
        'email' => [FieldType::Email],
        'customer_phone' => [FieldType::Phone, FieldType::Text],
        'country_code' => [FieldType::Country],
        'deadline' => [FieldType::Date],
        'word_limit' => [FieldType::Number],
    ];

    /**
     * @param  iterable<array<string, mixed>|ServiceField>  $fields
     * @return list<string>
     */
    public static function warnings(iterable $fields): array
    {
        $fields = self::normalise($fields);
        $warnings = [];

        foreach (self::ESSENTIAL as $mapping => $reason) {
            $mapped = array_filter($fields, fn (array $field): bool => $field['maps_to'] === $mapping);
            $usable = array_filter($mapped, fn (array $field): bool => $field['is_active'] && $field['requirement'] === RequirementLevel::Required);
            $label = FieldMapping::from($mapping)->getLabel();

            if ($mapped === []) {
                $warnings[] = "No question is mapped to “{$label}” — {$reason}. Add a question and set “Maps to order detail”.";
            } elseif ($usable === []) {
                $warnings[] = "The question mapped to “{$label}” must be shown on the form and required — {$reason}.";
            }
        }

        foreach ($fields as $field) {
            $expected = self::EXPECTED_TYPES[$field['maps_to']] ?? null;
            if ($expected !== null && $field['type'] !== null && ! in_array($field['type'], $expected, true)) {
                $warnings[] = sprintf(
                    '“%s” is mapped to %s but uses the “%s” answer type; “%s” works best.',
                    $field['label'] ?: $field['key'],
                    FieldMapping::from($field['maps_to'])->getLabel(),
                    $field['type']->getLabel(),
                    $expected[0]->getLabel(),
                );
            }
        }

        return $warnings;
    }

    /** @return list<string> */
    public static function warningsFor(Service $service): array
    {
        return self::warnings($service->fields()->get());
    }

    /**
     * @param  iterable<array<string, mixed>|ServiceField>  $fields
     * @return list<array{key:string,label:string,type:?FieldType,requirement:?RequirementLevel,maps_to:?string,is_active:bool}>
     */
    private static function normalise(iterable $fields): array
    {
        $normalised = [];

        foreach ($fields as $field) {
            if ($field instanceof ServiceField) {
                $field = $field->attributesToArray();
            }
            if (! is_array($field)) {
                continue;
            }

            $normalised[] = [
                'key' => (string) ($field['key'] ?? ''),
                'label' => (string) ($field['label'] ?? ''),
                'type' => ServiceFieldSchema::type($field['type'] ?? null),
                'requirement' => ServiceFieldSchema::requirement($field['requirement'] ?? null),
                'maps_to' => ServiceFieldSchema::mapping($field['maps_to'] ?? null)?->value,
                'is_active' => (bool) ($field['is_active'] ?? true),
            ];
        }

        return $normalised;
    }
}
