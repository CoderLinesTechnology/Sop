<?php

namespace App\Domain\Documents;

use Mpdf\Config\ConfigVariables;

/**
 * The fonts documents may use.
 *
 * DOCX files name the standard Microsoft fonts (Times New Roman, Arial,
 * Calibri, Cambria) so they open natively in Word. PDFs embed open-licence
 * fonts with identical character widths (Liberation Serif / Sans, Carlito,
 * Caladea in resources/fonts/document), so both files break lines and pages
 * alike. DejaVu Sans, shipped with mPDF, fills in any glyph the main font
 * lacks (e.g. Vietnamese or Yoruba diacritics in Caladea).
 */
final class FontRegistry
{
    public const DEFAULT_FAMILY = 'Times New Roman';

    /** mPDF font key of the glyph fallback. */
    public const FALLBACK_KEY = 'dejavusans';

    /**
     * key:   mPDF font key
     * files: file-name prefix in the fonts folder ("-Regular.ttf", "-Bold.ttf", ...)
     * line:  Word's single line height in ems for the real Microsoft font
     *        ((hhea ascender + descender + line gap) / unitsPerEm), so the PDF
     *        uses exactly the line pitch Word uses for "multiple" spacing.
     */
    private const FAMILIES = [
        'Times New Roman' => ['key' => 'timesnewroman', 'files' => 'LiberationSerif', 'line' => 2355 / 2048],
        'Arial' => ['key' => 'arial', 'files' => 'LiberationSans', 'line' => 2355 / 2048],
        'Calibri' => ['key' => 'calibri', 'files' => 'Carlito', 'line' => 2500 / 2048],
        'Cambria' => ['key' => 'cambria', 'files' => 'Caladea', 'line' => 2401 / 2048],
    ];

    private const ALIASES = [
        'times' => 'Times New Roman', 'times roman' => 'Times New Roman', 'tnr' => 'Times New Roman',
        'liberation serif' => 'Times New Roman', 'tinos' => 'Times New Roman',
        'helvetica' => 'Arial', 'liberation sans' => 'Arial', 'arimo' => 'Arial',
        'carlito' => 'Calibri',
        'caladea' => 'Cambria',
    ];

    private const STYLES = ['R' => 'Regular', 'B' => 'Bold', 'I' => 'Italic', 'BI' => 'BoldItalic'];

    private const FALLBACK_FILES = ['R' => 'DejaVuSans.ttf', 'B' => 'DejaVuSans-Bold.ttf', 'I' => 'DejaVuSans-Oblique.ttf', 'BI' => 'DejaVuSans-BoldOblique.ttf'];

    /** @var array<string, array<int, true>> code points per font file */
    private static array $coverage = [];

    /** @return list<string> */
    public static function families(): array
    {
        return array_keys(self::FAMILIES);
    }

    /** The canonical family for a name or alias ("helvetica" → "Arial"), or null when unsupported. */
    public static function normalize(?string $family): ?string
    {
        $value = trim(strtolower(preg_replace('/\s+/', ' ', (string) $family) ?? ''), " \"'");

        foreach (array_keys(self::FAMILIES) as $name) {
            if (strtolower($name) === $value) {
                return $name;
            }
        }

        return self::ALIASES[$value] ?? null;
    }

    public static function resolve(?string $family): string
    {
        return self::normalize($family) ?? self::DEFAULT_FAMILY;
    }

    public static function pdfKey(?string $family): string
    {
        return self::FAMILIES[self::resolve($family)]['key'];
    }

    public static function lineFactor(?string $family): float
    {
        return self::FAMILIES[self::resolve($family)]['line'];
    }

    public static function fontsPath(): string
    {
        return rtrim((string) config('statementra.documents.fonts_path', resource_path('fonts/document')), '/');
    }

    /** @return list<string> our fonts first, then mPDF's bundled fonts (DejaVu fallback) */
    public static function mpdfFontDirs(): array
    {
        return array_values(array_unique([self::fontsPath(), ...(array) (new ConfigVariables)->getDefaults()['fontDir']]));
    }

    /** @return array<string, array<string, string>> mPDF "fontdata" for every family plus the fallback */
    public static function mpdfFontData(): array
    {
        $data = [];
        foreach (self::FAMILIES as $family) {
            foreach (self::STYLES as $style => $suffix) {
                $data[$family['key']][$style] = "{$family['files']}-{$suffix}.ttf";
            }
        }
        $data[self::FALLBACK_KEY] = self::FALLBACK_FILES;

        return $data;
    }

    /** Absolute path of a family's font file (style R, B, I or BI). */
    public static function file(?string $family, string $style = 'R'): string
    {
        return self::fontsPath().'/'.self::FAMILIES[self::resolve($family)]['files'].'-'.(self::STYLES[$style] ?? 'Regular').'.ttf';
    }

    /**
     * Characters of $text that the family cannot draw: "fallback" ones are
     * drawn with DejaVu instead, "missing" ones would not render at all.
     *
     * @return array{fallback: list<string>, missing: list<string>}
     */
    public static function glyphCoverage(string $text, ?string $family): array
    {
        $primary = self::codepoints(self::file($family));
        $fallback = ($path = self::fallbackFile()) ? self::codepoints($path) : [];

        $result = ['fallback' => [], 'missing' => []];
        foreach (array_unique(mb_str_split(preg_replace('/\s+/u', '', $text) ?? '')) as $char) {
            $codepoint = mb_ord($char);
            if (isset($primary[$codepoint])) {
                continue;
            }
            $result[isset($fallback[$codepoint]) ? 'fallback' : 'missing'][] = $char;
        }

        return $result;
    }

    private static function fallbackFile(): ?string
    {
        foreach (self::mpdfFontDirs() as $directory) {
            if (is_file($path = $directory.'/'.self::FALLBACK_FILES['R'])) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Code points mapped by a TrueType font's cmap (formats 4 and 12).
     *
     * @return array<int, true>
     */
    public static function codepoints(string $path): array
    {
        if (isset(self::$coverage[$path])) {
            return self::$coverage[$path];
        }

        $data = is_file($path) ? (string) file_get_contents($path) : '';
        $codepoints = [];

        $cmap = self::tableOffset($data, 'cmap');
        if ($cmap !== null) {
            [$subtable, $format] = self::bestCmapSubtable($data, $cmap);
            if ($format === 12) {
                $groups = self::u32($data, $subtable + 12);
                for ($i = 0; $i < $groups; $i++) {
                    $start = self::u32($data, $subtable + 16 + 12 * $i);
                    $end = min(self::u32($data, $subtable + 20 + 12 * $i), 0x10FFFF);
                    for ($c = $start; $c <= $end; $c++) {
                        $codepoints[$c] = true;
                    }
                }
            } elseif ($format === 4) {
                $segX2 = self::u16($data, $subtable + 6);
                $ends = $subtable + 14;
                $starts = $ends + $segX2 + 2;
                $deltas = $starts + $segX2;
                $rangeOffsets = $deltas + $segX2;
                for ($s = 0; $s < $segX2 / 2; $s++) {
                    $end = self::u16($data, $ends + 2 * $s);
                    $start = self::u16($data, $starts + 2 * $s);
                    $delta = self::u16($data, $deltas + 2 * $s);
                    $rangeOffset = self::u16($data, $rangeOffsets + 2 * $s);
                    for ($c = $start; $c <= $end && $c !== 0xFFFF; $c++) {
                        if ($rangeOffset === 0) {
                            $glyph = ($c + $delta) & 0xFFFF;
                        } else {
                            $glyph = self::u16($data, $rangeOffsets + 2 * $s + $rangeOffset + 2 * ($c - $start));
                            $glyph = $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
                        }
                        if ($glyph !== 0) {
                            $codepoints[$c] = true;
                        }
                    }
                }
            }
        }

        return self::$coverage[$path] = $codepoints;
    }

    private static function tableOffset(string $data, string $tag): ?int
    {
        if (strlen($data) < 12) {
            return null;
        }
        $tables = self::u16($data, 4);
        for ($i = 0; $i < $tables; $i++) {
            if (substr($data, 12 + 16 * $i, 4) === $tag) {
                return self::u32($data, 12 + 16 * $i + 8);
            }
        }

        return null;
    }

    /** @return array{0:int,1:int} subtable offset and format (Unicode subtables only) */
    private static function bestCmapSubtable(string $data, int $cmap): array
    {
        $best = [0, 0];
        $count = self::u16($data, $cmap + 2);
        for ($i = 0; $i < $count; $i++) {
            $platform = self::u16($data, $cmap + 4 + 8 * $i);
            $encoding = self::u16($data, $cmap + 6 + 8 * $i);
            $offset = $cmap + self::u32($data, $cmap + 8 + 8 * $i);
            $format = self::u16($data, $offset);
            $unicode = $platform === 0 || ($platform === 3 && in_array($encoding, [1, 10], true));
            if ($unicode && $format === 12) {
                return [$offset, 12];
            }
            if ($unicode && $format === 4 && $best[1] !== 4) {
                $best = [$offset, 4];
            }
        }

        return $best;
    }

    private static function u16(string $data, int $offset): int
    {
        return $offset + 2 <= strlen($data) ? unpack('n', $data, $offset)[1] : 0;
    }

    private static function u32(string $data, int $offset): int
    {
        return $offset + 4 <= strlen($data) ? unpack('N', $data, $offset)[1] : 0;
    }
}
