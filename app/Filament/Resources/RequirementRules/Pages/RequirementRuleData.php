<?php

namespace App\Filament\Resources\RequirementRules\Pages;

/** Normalises requirement-rule form data before it is stored. */
final class RequirementRuleData
{
    public static function clean(array $data): array
    {
        if (isset($data['country_code'])) {
            $data['country_code'] = strtoupper((string) $data['country_code']) ?: null;
        }

        if (isset($data['institution_domain'])) {
            $domain = strtolower(trim((string) $data['institution_domain']));
            $data['institution_domain'] = preg_replace('#^(https?://)?(www\.)?#', '', rtrim($domain, '/')) ?: null;
        }

        foreach (['document_kinds', 'file_types', 'prohibited_content'] as $list) {
            if (array_key_exists($list, $data)) {
                $values = array_values(array_filter(array_map(
                    fn ($value) => $value instanceof \BackedEnum ? $value->value : trim((string) $value),
                    (array) ($data[$list] ?? []),
                ), fn (string $value): bool => $value !== ''));
                $data[$list] = $values ?: null;
            }
        }

        if (array_key_exists('required_sections', $data)) {
            $sections = array_values(array_filter(array_map(fn ($section) => is_array($section) ? array_filter([
                'heading' => trim((string) ($section['heading'] ?? '')),
                'question' => filled($section['question'] ?? null) ? trim((string) $section['question']) : null,
                'min_characters' => is_numeric($section['min_characters'] ?? null) ? (int) $section['min_characters'] : null,
                'max_characters' => is_numeric($section['max_characters'] ?? null) ? (int) $section['max_characters'] : null,
                'min_words' => is_numeric($section['min_words'] ?? null) ? (int) $section['min_words'] : null,
                'max_words' => is_numeric($section['max_words'] ?? null) ? (int) $section['max_words'] : null,
            ], fn ($value) => $value !== null && $value !== '') : null, (array) ($data['required_sections'] ?? []))));
            $data['required_sections'] = $sections ?: null;
        }

        return $data;
    }
}
