<?php

namespace App\Domain\Documents\Qa;

use Normalizer;

/**
 * Normalises text extracted from rendered files so it can be compared with
 * the DocumentModel exactly: NFKC (ligatures, compatibility forms), one kind
 * of quote and dash, no invisible characters, and — for comparison — no
 * whitespace at all, so line wrapping and justification never matter while
 * every visible character still has to match, in order.
 */
final class TextNormalizer
{
    /** Marks a hyphen that ended a line in the PDF (possibly inserted by hyphenation). */
    public const LINE_END_HYPHEN = "\u{E000}";

    public static function normalize(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        } else {
            $text = strtr($text, ['ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬀ' => 'ff', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl', '…' => '...', "\u{00A0}" => ' ']);
        }

        $text = strtr($text, [
            '‘' => "'", '’' => "'", '‚' => "'", '‛' => "'", '′' => "'", '`' => "'",
            '“' => '"', '”' => '"', '„' => '"', '‟' => '"', '″' => '"', '«' => '"', '»' => '"',
            '‐' => '-', '‑' => '-', '‒' => '-', '–' => '-', '—' => '-', '―' => '-', '−' => '-',
        ]);

        // Soft hyphens, zero-width characters and other invisible format characters.
        $text = preg_replace('/[\x{00AD}\p{Cf}]/u', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r", "\f", "\t"], ["\n", "\n", "\n", ' '], $text);

        return trim(preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text);
    }

    /** Normalised text without any whitespace. */
    public static function compact(string $text): string
    {
        return preg_replace('/\s+/u', '', self::normalize($text)) ?? '';
    }

    /**
     * Like compact(), but a hyphen at the end of a line is replaced by a
     * marker so the comparison can tell "self-\nmotivated" (a real hyphen)
     * from "inter-\nnational" (hyphenation inserted by the renderer).
     */
    public static function compactExtracted(string $text): string
    {
        $text = self::normalize($text);
        $text = preg_replace('/(?<=\pL)-[ ]*\n(?=[ ]*\pL)/u', self::LINE_END_HYPHEN."\n", $text) ?? $text;

        return preg_replace('/\s+/u', '', $text) ?? '';
    }

    /**
     * Extracted text as compacted, non-empty lines (line-end hyphens marked),
     * so header, footer and page-number lines can be recognised and removed.
     *
     * @return list<string>
     */
    public static function extractedLines(string $text): array
    {
        $text = self::normalize($text);
        $text = preg_replace('/(?<=\pL)-[ ]*\n(?=[ ]*\pL)/u', self::LINE_END_HYPHEN."\n", $text) ?? $text;

        return array_values(array_filter(array_map(
            fn (string $line) => preg_replace('/\s+/u', '', $line) ?? '',
            explode("\n", $text),
        ), fn (string $line) => $line !== ''));
    }

    /**
     * Compare expected (model) text with extracted text, both compacted.
     * Hyphenation is undone: a hyphen that ended a line in the extraction
     * matches a real hyphen or nothing, and a hyphen pdftotext dropped while
     * joining a word split across lines ("self-|motivated") is tolerated.
     *
     * @return array{equal:bool, detail:string}
     */
    public static function compare(string $expected, string $actual, string $label = 'File'): array
    {
        $e = mb_str_split($expected);
        $a = mb_str_split($actual);
        $i = $j = 0;
        $ne = count($e);
        $na = count($a);

        while ($i < $ne && $j < $na) {
            if ($a[$j] === self::LINE_END_HYPHEN) {
                $j++;
                if ($e[$i] === '-') {
                    $i++;
                }

                continue;
            }
            if ($e[$i] !== $a[$j]) {
                if ($e[$i] === '-' && $i > 0 && $i + 1 < $ne && $e[$i + 1] === $a[$j]) {
                    $i++; // hyphen removed by the extractor's de-hyphenation

                    continue;
                }

                break;
            }
            $i++;
            $j++;
        }
        while ($j < $na && $a[$j] === self::LINE_END_HYPHEN) {
            $j++;
        }

        if ($i === $ne && $j === $na) {
            return ['equal' => true, 'detail' => "{$label} text matches the approved content exactly ({$ne} visible characters)."];
        }

        $context = fn (array $chars, int $at) => self::visible(implode('', array_slice($chars, max(0, $at - 30), $at - max(0, $at - 30))));
        $after = fn (array $chars, int $at) => self::visible(implode('', array_slice($chars, $at, 40)));

        if ($j >= $na) {
            return ['equal' => false, 'detail' => sprintf(
                '%s is missing text (clipped or truncated): %d of %d characters found; missing from “%s”.',
                $label, $i, $ne, $after($e, $i),
            )];
        }
        if ($i >= $ne) {
            return ['equal' => false, 'detail' => sprintf('%s contains unexpected extra text after the approved content: “%s”.', $label, $after($a, $j))];
        }

        return ['equal' => false, 'detail' => sprintf(
            '%s text differs from the approved content after “%s”: expected “%s”, found “%s”.',
            $label, $context($e, $i), $after($e, $i), $after($a, $j),
        )];
    }

    private static function visible(string $text): string
    {
        return str_replace(self::LINE_END_HYPHEN, '-', $text);
    }
}
