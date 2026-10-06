<?php

namespace App\Filament\Support\Catalogue;

use App\Models\DocumentTemplate;
use BackedEnum;

/** Normalisation, the single-default rule and audit snapshots for document templates. */
final class DocumentTemplateData
{
    public const AUDITED = [
        'name', 'slug', 'description', 'is_default', 'is_active', 'priority', 'match_rules', 'page_size',
        'margin_top_mm', 'margin_right_mm', 'margin_bottom_mm', 'margin_left_mm', 'font_family', 'font_size',
        'line_spacing', 'paragraph_spacing_pt', 'first_line_indent_mm', 'text_align', 'show_title', 'title_template',
        'title_font_size', 'title_align', 'heading_font_size', 'applicant_name_position', 'header_text', 'footer_text',
        'page_numbers', 'page_number_format', 'date_format', 'citation_style', 'filename_pattern',
        'include_name_in_filename', 'include_branding',
    ];

    /** match_rules: drop empty criteria, ints for services, upper-case countries, trimmed strings elsewhere. */
    public static function clean(array $data): array
    {
        if (array_key_exists('match_rules', $data)) {
            $rules = [];
            foreach ((array) ($data['match_rules'] ?? []) as $criterion => $values) {
                $values = array_values(array_unique(array_filter(array_map(function (mixed $value) use ($criterion) {
                    $value = $value instanceof BackedEnum ? $value->value : $value;

                    return match ($criterion) {
                        'services' => is_numeric($value) ? (int) $value : null,
                        'countries' => is_string($value) && $value !== '' ? strtoupper(trim($value)) : null,
                        default => is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null,
                    };
                }, (array) $values), fn ($value) => $value !== null)));

                if ($values !== []) {
                    $rules[$criterion] = $values;
                }
            }
            $data['match_rules'] = $rules ?: null;
        }

        return $data;
    }

    /** Keep a single default template (call after saving $template as default). */
    public static function makeDefault(DocumentTemplate $template): void
    {
        DocumentTemplate::query()
            ->whereKeyNot($template->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false, 'updated_at' => now()]);
    }
}
