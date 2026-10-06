<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * Formatting template for generated documents. match_rules selects the
 * template automatically by service, document kind, country or institution:
 * {"services":[ids], "document_kinds":[...], "countries":["GB"], "institutions":["..."]}.
 */
#[Unguarded]
class DocumentTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'match_rules' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'show_title' => 'boolean',
            'include_name_in_filename' => 'boolean',
            'include_branding' => 'boolean',
            'margin_top_mm' => 'float',
            'margin_right_mm' => 'float',
            'margin_bottom_mm' => 'float',
            'margin_left_mm' => 'float',
            'font_size' => 'float',
            'line_spacing' => 'float',
            'paragraph_spacing_pt' => 'float',
            'first_line_indent_mm' => 'float',
            'title_font_size' => 'float',
            'heading_font_size' => 'float',
        ];
    }

    /** Fonts offered to administrators; each has a metric-compatible embedded PDF font. */
    public const FONTS = [
        'Times New Roman' => 'Times New Roman (serif)',
        'Arial' => 'Arial (sans-serif)',
        'Calibri' => 'Calibri (sans-serif)',
        'Cambria' => 'Cambria (serif)',
    ];

    public static function default(): ?self
    {
        return static::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first();
    }
}
