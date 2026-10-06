<?php

namespace App\Filament\Support\Catalogue;

use App\Models\DocumentTemplate;
use Illuminate\Support\Facades\Cache;
use ResourceBundle;

/**
 * Option lists for formatting templates and requirement rules. The values
 * are the vocabulary the document engine reads; keep them in sync with it.
 */
final class DocumentFormatOptions
{
    public const PAGE_SIZES = ['A4' => 'A4 (210 × 297 mm)', 'Letter' => 'US Letter (8.5 × 11 in)'];

    public const TEXT_ALIGN = ['left' => 'Left', 'justify' => 'Justified', 'center' => 'Centred', 'right' => 'Right'];

    public const TITLE_ALIGN = ['left' => 'Left', 'center' => 'Centred', 'right' => 'Right'];

    /** Same values as App\Domain\Documents\TemplateSnapshot::NAME_POSITIONS. */
    public const NAME_POSITIONS = [
        'below_title' => 'Below the title',
        'above_title' => 'Above the title',
        'header' => 'In the page header',
        'footer' => 'In the page footer',
        'none' => 'Not shown',
    ];

    /** Same values as App\Domain\Documents\TemplateSnapshot::PAGE_NUMBER_POSITIONS. */
    public const PAGE_NUMBERS = [
        'none' => 'No page numbers',
        'bottom_center' => 'Bottom, centred',
        'bottom_right' => 'Bottom, right',
        'bottom_left' => 'Bottom, left',
        'top_center' => 'Top, centred',
        'top_right' => 'Top, right',
        'top_left' => 'Top, left',
    ];

    public const CITATION_STYLES = ['none' => 'None', 'apa' => 'APA', 'harvard' => 'Harvard', 'mla' => 'MLA', 'chicago' => 'Chicago', 'ieee' => 'IEEE'];

    /** PHP date formats with an example. */
    public const DATE_FORMATS = [
        'j F Y' => '6 October 2026',
        'F j, Y' => 'October 6, 2026',
        'd/m/Y' => '06/10/2026',
        'm/d/Y' => '10/06/2026',
        'Y-m-d' => '2026-10-06',
        'd.m.Y' => '06.10.2026',
    ];

    public const FILE_TYPES = ['pdf' => 'PDF', 'docx' => 'Word (.docx)'];

    public const DEGREE_LEVELS = [
        'undergraduate' => "Undergraduate / Bachelor's",
        'masters' => "Master's",
        'mba' => 'MBA',
        'phd' => 'PhD / Doctorate',
        'postgraduate_diploma' => 'Postgraduate diploma / certificate',
        'exchange' => 'Exchange / visiting',
        'other' => 'Other',
    ];

    public const PLATFORMS = ['UCAS', 'Common App', 'Coalition App', 'OUAC', 'Studielink', 'uni-assist', 'Universityadmissions.se', 'Campus France', 'DAAD', 'Chevening', 'Fulbright'];

    /** @return array<string, string> */
    public static function fonts(?string $current = null): array
    {
        return IconOptions::withCurrent(DocumentTemplate::FONTS, $current);
    }

    /** @return array<string, string> ISO 3166-1 alpha-2 code => "Name (CODE)" */
    public static function countries(): array
    {
        return Cache::rememberForever('admin:countries:en:v1', function (): array {
            $countries = [];
            $table = class_exists(ResourceBundle::class) ? ResourceBundle::create('en', 'ICUDATA-region')?->get('Countries') : null;

            if ($table) {
                foreach ($table as $code => $name) {
                    if (is_string($code) && preg_match('/^[A-Z]{2}$/', $code) && ! in_array($code, ['ZZ', 'EU', 'EZ', 'UN', 'QO', 'XA', 'XB'], true)) {
                        $countries[$code] = $name.' ('.$code.')';
                    }
                }
            }

            if ($countries === []) {
                foreach (['GB' => 'United Kingdom', 'US' => 'United States', 'CA' => 'Canada', 'AU' => 'Australia', 'NZ' => 'New Zealand', 'IE' => 'Ireland', 'DE' => 'Germany', 'NL' => 'Netherlands', 'FR' => 'France', 'GH' => 'Ghana', 'NG' => 'Nigeria', 'KE' => 'Kenya', 'ZA' => 'South Africa', 'IN' => 'India'] as $code => $name) {
                    $countries[$code] = $name.' ('.$code.')';
                }
            }

            asort($countries, SORT_NATURAL | SORT_FLAG_CASE);

            return $countries;
        });
    }
}
