<?php

namespace App\Domain\Documents;

use Illuminate\Support\Str;

/**
 * Clean, professional file names such as Daniel_Essel_Statement_of_Purpose.pdf,
 * built from the template's filename_pattern ({applicant_name},
 * {document_type}, {institution}, {programme}). Accents are transliterated,
 * unsafe characters removed and internal ids never appear. When the template
 * keeps names out of file names the name is simply omitted
 * (Statement_of_Purpose.pdf); without a usable name the brand is used
 * instead (Statementra_Statement_of_Purpose.pdf).
 */
final class FileNamer
{
    public const MAX_LENGTH = 100;

    public static function filename(array $snapshot, ?string $applicantName, string $extension): string
    {
        return self::base($snapshot, $applicantName).'.'.strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?? '');
    }

    /** The file name without extension. */
    public static function base(array $snapshot, ?string $applicantName): string
    {
        $s = TemplateSnapshot::normalize($snapshot);
        $context = $s['context'];
        $documentType = self::slug((string) $context['document_type']) ?: 'Document';
        $brand = self::slug((string) ($context['brand'] ?? '')) ?: 'Statementra';
        $includeName = $s['include_name_in_filename'];
        $name = $includeName ? self::slug((string) $applicantName) : '';

        $pattern = (string) $s['filename_pattern'];
        $usesName = preg_match('/\{\s*(applicant_name|name)\s*\}/i', $pattern) === 1;

        $base = preg_replace_callback('/\{\s*([a-z_]+)\s*\}/i', fn (array $m) => match (strtolower($m[1])) {
            'applicant_name', 'name' => $name,
            'document_type', 'type' => $documentType,
            'institution' => self::slug((string) ($context['institution'] ?? '')),
            'programme', 'program' => self::slug((string) ($context['programme'] ?? '')),
            default => '',
        }, $pattern) ?? '';
        $base = self::slug($base);

        if ($usesName && $includeName && $name === '') {
            $base = self::slug($brand.'_'.$base);
        }
        if ($base === '' || $base === $brand) {
            $base = $brand.'_'.$documentType;
        }

        return self::truncate($base);
    }

    /** "Zoë Ångström-Nwosu" → "Zoe_Angstrom-Nwosu"; "José O’Neill" → "Jose_ONeill". */
    public static function slug(string $value): string
    {
        $ascii = Str::ascii($value);
        if (trim($ascii) === '' && trim($value) !== '' && function_exists('transliterator_transliterate')) {
            // Non-Latin scripts: romanise (e.g. Chinese → Pinyin) rather than dropping the name.
            $ascii = ucwords((string) transliterator_transliterate('Any-Latin; Latin-ASCII', $value));
        }

        $ascii = str_replace(["'", '`', '’', '‘'], '', $ascii);
        $ascii = preg_replace('/[^A-Za-z0-9-]+/', '_', $ascii) ?? '';
        // Collapse separator runs: "A - B" → "A_B", "x__y" → "x_y"; keep hyphenated names ("Angstrom-Nwosu").
        $ascii = preg_replace(['/[_-]*_[_-]*/', '/-{2,}/'], ['_', '-'], $ascii) ?? '';

        return trim($ascii, '_-');
    }

    private static function truncate(string $base): string
    {
        if (strlen($base) <= self::MAX_LENGTH) {
            return $base;
        }

        $cut = substr($base, 0, self::MAX_LENGTH);
        $boundary = strrpos($cut, '_');

        return trim($boundary !== false && $boundary > self::MAX_LENGTH * 0.6 ? substr($cut, 0, $boundary) : $cut, '_-');
    }
}
