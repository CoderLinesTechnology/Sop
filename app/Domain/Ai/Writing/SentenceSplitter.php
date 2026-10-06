<?php

namespace App\Domain\Ai\Writing;

/** Pragmatic English sentence splitter for linting and fact checks. */
final class SentenceSplitter
{
    private const ABBREVIATIONS = [
        'e.g.', 'i.e.', 'etc.', 'vs.', 'cf.', 'approx.', 'Dr.', 'Mr.', 'Mrs.', 'Ms.', 'Prof.', 'St.', 'No.', 'Inc.',
        'Ltd.', 'Co.', 'Jr.', 'Sr.', 'Ph.D.', 'B.Sc.', 'M.Sc.', 'B.A.', 'M.A.', 'U.S.', 'U.K.', 'a.m.', 'p.m.', 'Dept.',
    ];

    private const PLACEHOLDER = "\u{2024}"; // one-dot leader, restored after splitting

    /** @return list<string> */
    public static function split(string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return [];
        }

        foreach (self::ABBREVIATIONS as $abbreviation) {
            $text = str_ireplace($abbreviation, str_replace('.', self::PLACEHOLDER, $abbreviation), $text);
        }
        // Decimal numbers and initials ("3.8", "J. K.") must not end sentences.
        $text = preg_replace('/(\d)\.(\d)/u', '$1'.self::PLACEHOLDER.'$2', $text) ?? $text;

        $parts = preg_split('/(?:(?<=[.!?…])|(?<=[.!?…]["\'”’)\]]))\s+(?=["“‘(\[]?[\p{Lu}\p{N}])/u', $text) ?: [$text];

        return array_values(array_filter(array_map(
            fn (string $s) => trim(str_replace(self::PLACEHOLDER, '.', $s)),
            $parts,
        ), fn (string $s) => $s !== ''));
    }

    /** @return list<string> lower-case words of a sentence */
    public static function words(string $sentence): array
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', mb_strtolower($sentence), $matches);

        return $matches[0];
    }
}
