<?php

namespace App\Domain\Documents;

use App\Models\DocumentTemplate;

/**
 * The formatting a version was rendered with: the template's settings with
 * the resolved requirement overrides (page size, font, size, margins, line
 * spacing) applied. Stored on the version so a re-render years later looks
 * exactly the same, even if the template has since been edited or deleted.
 */
final class TemplateSnapshot
{
    public const VERSION = 1;

    public const PAGE_SIZES = ['A4' => [210.0, 297.0], 'Letter' => [215.9, 279.4]];

    public const NAME_POSITIONS = ['below_title', 'above_title', 'header', 'footer', 'none'];

    public const PAGE_NUMBER_POSITIONS = ['none', 'bottom_center', 'bottom_right', 'bottom_left', 'top_center', 'top_right', 'top_left'];

    /** Template columns captured in the snapshot. */
    private const FIELDS = [
        'page_size', 'margin_top_mm', 'margin_right_mm', 'margin_bottom_mm', 'margin_left_mm', 'font_family',
        'font_size', 'line_spacing', 'paragraph_spacing_pt', 'first_line_indent_mm', 'text_align', 'show_title',
        'title_template', 'title_font_size', 'title_align', 'heading_font_size', 'applicant_name_position',
        'header_text', 'footer_text', 'page_numbers', 'page_number_format', 'date_format', 'citation_style',
        'filename_pattern', 'include_name_in_filename', 'include_branding',
    ];

    /** Professional defaults ("Standard application (A4)"), used for anything a template leaves empty. */
    public static function defaults(): array
    {
        return [
            'page_size' => 'A4',
            'margin_top_mm' => 25.4,
            'margin_right_mm' => 25.4,
            'margin_bottom_mm' => 25.4,
            'margin_left_mm' => 25.4,
            'font_family' => FontRegistry::DEFAULT_FAMILY,
            'font_size' => 12.0,
            'line_spacing' => 1.5,
            'paragraph_spacing_pt' => 8.0,
            'first_line_indent_mm' => 0.0,
            'text_align' => 'left',
            'show_title' => true,
            'title_template' => null,
            'title_font_size' => 14.0,
            'title_align' => 'center',
            'heading_font_size' => 12.0,
            'applicant_name_position' => 'below_title',
            'header_text' => null,
            'footer_text' => null,
            'page_numbers' => 'bottom_center',
            'page_number_format' => '{PAGE}',
            'date_format' => null,
            'citation_style' => 'none',
            'filename_pattern' => '{applicant_name}_{document_type}',
            'include_name_in_filename' => true,
            'include_branding' => false,
        ];
    }

    /**
     * @param  array{document_kind?:string, document_type?:string, institution?:?string, programme?:?string}  $context
     * @param  bool  $preferTemplate  the template was chosen explicitly (by an administrator): its
     *                                formatting beats country-level conventions, but not real requirements
     */
    public static function make(DocumentTemplate $template, ResolvedRequirements $requirements, array $context = [], bool $preferTemplate = false): array
    {
        $snapshot = self::fromTemplate($template);
        $overrides = [];
        $ignored = [];

        $override = function (string $field, mixed $value, string $requirement) use (&$snapshot, &$overrides, &$ignored, $preferTemplate, $requirements): void {
            if ($snapshot[$field] == $value) {
                return;
            }
            if ($preferTemplate && $requirements->isConvention($requirement)) {
                $ignored[$field] = ['requested' => $value, 'reason' => 'Country convention; the explicitly chosen template keeps its own setting.'];

                return;
            }
            $overrides[$field] = ['template' => $snapshot[$field], 'applied' => $value, 'requirement' => $requirement];
            $snapshot[$field] = $value;
        };

        if ($requirements->pageSize !== null) {
            if ($size = self::pageSize($requirements->pageSize)) {
                $override('page_size', $size, 'pageSize');
            } else {
                $ignored['page_size'] = ['requested' => $requirements->pageSize, 'reason' => 'Unsupported paper size; the template size is used.'];
            }
        }

        if ($requirements->fontFamily !== null) {
            if ($family = FontRegistry::normalize($requirements->fontFamily)) {
                $override('font_family', $family, 'fontFamily');
            } else {
                $ignored['font_family'] = ['requested' => $requirements->fontFamily, 'reason' => 'No embeddable metric-compatible font is available; the template font is used.'];
            }
        }

        if ($requirements->fontSize !== null) {
            if (self::between($requirements->fontSize, 8, 20)) {
                $override('font_size', (float) $requirements->fontSize, 'fontSize');
            } else {
                $ignored['font_size'] = ['requested' => $requirements->fontSize, 'reason' => 'Outside the supported range (8–20 pt).'];
            }
        }

        if ($requirements->marginsMm !== null) {
            if (self::between($requirements->marginsMm, 5, 60)) {
                foreach (['margin_top_mm', 'margin_right_mm', 'margin_bottom_mm', 'margin_left_mm'] as $margin) {
                    $override($margin, (float) $requirements->marginsMm, 'marginsMm');
                }
            } else {
                $ignored['margins_mm'] = ['requested' => $requirements->marginsMm, 'reason' => 'Outside the supported range (5–60 mm).'];
            }
        }

        if ($requirements->lineSpacing !== null) {
            if (self::between($requirements->lineSpacing, 0.8, 3)) {
                $override('line_spacing', (float) $requirements->lineSpacing, 'lineSpacing');
            } else {
                $ignored['line_spacing'] = ['requested' => $requirements->lineSpacing, 'reason' => 'Outside the supported range (0.8–3).'];
            }
        }

        $snapshot['date_format'] ??= $requirements->dateFormat;
        $snapshot['language_variant'] = $requirements->languageVariant;
        $snapshot['template_chosen'] = $preferTemplate;
        $snapshot['applied_overrides'] = $overrides;
        $snapshot['ignored_overrides'] = $ignored;
        $snapshot['context'] = array_merge($snapshot['context'], array_filter($context, fn ($v) => $v !== null && $v !== ''));

        return $snapshot;
    }

    /** The template's own formatting (no requirement overrides). */
    public static function fromTemplate(DocumentTemplate $template): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = $template->getAttribute($field);
        }

        return self::normalize($values + [
            'template_id' => $template->exists ? $template->getKey() : null,
            'template_name' => $template->name,
            'template_slug' => $template->slug,
        ]);
    }

    /** Fill gaps with defaults and clamp values to what the renderers support (also used for old snapshots). */
    public static function normalize(array $snapshot): array
    {
        $defaults = self::defaults();
        $s = [];
        foreach ($defaults as $field => $default) {
            $value = $snapshot[$field] ?? null;
            $s[$field] = ($value === null || $value === '') ? $default : $value;
        }

        $s['page_size'] = self::pageSize((string) $s['page_size']) ?? 'A4';
        foreach (['margin_top_mm', 'margin_right_mm', 'margin_bottom_mm', 'margin_left_mm'] as $margin) {
            $s[$margin] = self::clamp($s[$margin], 5, 60);
        }
        $s['font_family'] = FontRegistry::resolve((string) $s['font_family']);
        $s['font_size'] = self::clamp($s['font_size'], 8, 20);
        $s['line_spacing'] = self::clamp($s['line_spacing'], 0.8, 3);
        $s['paragraph_spacing_pt'] = self::clamp($s['paragraph_spacing_pt'], 0, 36);
        $s['first_line_indent_mm'] = self::clamp($s['first_line_indent_mm'], 0, 30);
        $s['text_align'] = self::align((string) $s['text_align'], ['left', 'justify', 'center', 'right'], 'left');
        $s['title_font_size'] = self::clamp($s['title_font_size'], 8, 32);
        $s['title_align'] = self::align((string) $s['title_align'], ['left', 'center', 'right'], 'center');
        $s['heading_font_size'] = self::clamp($s['heading_font_size'], 8, 24);
        $s['applicant_name_position'] = in_array($s['applicant_name_position'], self::NAME_POSITIONS, true) ? $s['applicant_name_position'] : 'below_title';
        $s['page_numbers'] = in_array($s['page_numbers'], self::PAGE_NUMBER_POSITIONS, true) ? $s['page_numbers'] : 'bottom_center';
        $s['page_number_format'] = mb_substr((string) $s['page_number_format'], 0, 60);
        $s['show_title'] = (bool) $s['show_title'];
        $s['include_name_in_filename'] = (bool) $s['include_name_in_filename'];
        $s['include_branding'] = (bool) $s['include_branding'];

        foreach (['title_template', 'header_text', 'footer_text', 'date_format'] as $field) {
            $s[$field] = filled($s[$field]) ? mb_substr(trim((string) $s[$field]), 0, 255) : null;
        }

        $s['version'] = self::VERSION;
        $s['template_id'] = $snapshot['template_id'] ?? null;
        $s['template_name'] = $snapshot['template_name'] ?? null;
        $s['template_slug'] = $snapshot['template_slug'] ?? null;
        $s['language_variant'] = LanguageVariant::normalize($snapshot['language_variant'] ?? null) ?? LanguageVariant::DEFAULT;
        $s['template_chosen'] = (bool) ($snapshot['template_chosen'] ?? false);
        $s['applied_overrides'] = (array) ($snapshot['applied_overrides'] ?? []);
        $s['ignored_overrides'] = (array) ($snapshot['ignored_overrides'] ?? []);
        $s['context'] = (array) ($snapshot['context'] ?? []) + [
            'document_kind' => 'general_essay',
            'document_type' => 'Application Document',
            'institution' => null,
            'programme' => null,
            'brand' => (string) config('statementra.brand.name', 'Statementra'),
        ];

        return $s;
    }

    /** An unsaved template carrying a snapshot's formatting (to re-render after the template was deleted). */
    public static function toTemplate(array $snapshot): DocumentTemplate
    {
        $snapshot = self::normalize($snapshot);
        $template = new DocumentTemplate;
        $template->forceFill(array_intersect_key($snapshot, array_flip(self::FIELDS)) + [
            'name' => $snapshot['template_name'] ?? 'Snapshot',
            'slug' => $snapshot['template_slug'] ?? 'snapshot',
            'is_active' => true,
            'is_default' => false,
            'priority' => 0,
            'match_rules' => [],
        ]);

        return $template;
    }

    /** Page width and height in millimetres. @return array{0:float,1:float} */
    public static function pageDimensions(array $snapshot): array
    {
        return self::PAGE_SIZES[$snapshot['page_size'] ?? 'A4'] ?? self::PAGE_SIZES['A4'];
    }

    public static function pageSize(string $value): ?string
    {
        return match (strtolower(trim(str_replace(['-', '_'], ' ', $value)))) {
            'a4' => 'A4',
            'letter', 'us letter', 'usletter', 'letter size', 'us' => 'Letter',
            default => null,
        };
    }

    private static function align(string $value, array $allowed, string $default): string
    {
        $value = strtolower(trim($value));
        $value = ['both' => 'justify', 'justified' => 'justify', 'centre' => 'center', 'centred' => 'center', 'start' => 'left', 'end' => 'right'][$value] ?? $value;

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function clamp(mixed $value, float $min, float $max): float
    {
        return round(max($min, min($max, (float) $value)), 2);
    }

    private static function between(mixed $value, float $min, float $max): bool
    {
        return is_numeric($value) && (float) $value >= $min && (float) $value <= $max;
    }
}
