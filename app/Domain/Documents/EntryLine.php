<?php

namespace App\Domain\Documents;

/**
 * A CV entry line ("Science Teacher | Messiah Baptist JHS | 2023 – Present")
 * split into its parts for a styled layout: the role, the organisation and,
 * when the last part reads as dates, the dates (shown in square brackets at
 * the right margin). (Owned by the document engine.)
 *
 * Presentation only: the stored text is never changed. The displayed text
 * is "Role | Organisation", a tab, then "[Dates]"; both renderers and file
 * QA use that same text. A file read back by DocxImporter keeps
 * "Role | Organisation [Dates]", which parse() reads the same way.
 */
final class EntryLine
{
    private const MAX_DATES_LENGTH = 40;

    /** Words that may appear in a date range besides numbers and punctuation. */
    private const DATE_WORDS = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?|spring|summer|autumn|fall|winter|present|current|now|ongoing|today|to|until|since|from|expected|exp|date)';

    /**
     * @return array{role:string, organisation:?string, dates:?string}
     */
    public static function parse(string $text): array
    {
        $parts = array_values(array_filter(
            array_map('trim', preg_split('/\s*\|\s*/u', trim($text)) ?: []),
            fn (string $part) => $part !== '',
        ));
        if ($parts === []) {
            return ['role' => trim($text), 'organisation' => null, 'dates' => null];
        }

        // An entry line read back from a styled file: "Role | Organisation [2023 – Present]".
        $last = $parts[count($parts) - 1];
        if (preg_match('/^(.*\S)\s+\[([^\[\]]+)\]$/u', $last, $m) === 1 && self::looksLikeDates($m[2])) {
            array_splice($parts, -1, 1, [$m[1], $m[2]]);
        }

        $dates = null;
        if (count($parts) >= 2 && self::looksLikeDates($parts[count($parts) - 1])) {
            $dates = self::unbracket(array_pop($parts));
        }
        $role = array_shift($parts);

        return ['role' => $role, 'organisation' => $parts === [] ? null : implode(' | ', $parts), 'dates' => $dates];
    }

    /** Text as displayed: "Role | Organisation", then a tab and "[Dates]" when there are dates. */
    public static function display(array $entry): string
    {
        return $entry['role']
            .($entry['organisation'] !== null ? ' | '.$entry['organisation'] : '')
            .($entry['dates'] !== null ? "\t[".$entry['dates'].']' : '');
    }

    /** "2023 – Present", "Sept 2021 – June 2023", "[2019 - 2023]", "Expected 2026": a year or "present" and nothing but date words. */
    public static function looksLikeDates(string $text): bool
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_DATES_LENGTH) {
            return false;
        }
        if (preg_match('/\b(?:19|20)\d{2}\b|\b(?:present|current|now|ongoing|today)\b/iu', $text) !== 1) {
            return false;
        }

        $rest = preg_replace('/\b'.self::DATE_WORDS.'\b\.?/iu', '', $text) ?? $text;

        return (preg_replace('/[\d\s\p{Pd}\/.,()\[\]\'’]+/u', '', $rest) ?? $rest) === '';
    }

    private static function unbracket(string $dates): string
    {
        return preg_match('/^\[(.+)\]$/u', $dates, $m) === 1 ? trim($m[1]) : $dates;
    }
}
