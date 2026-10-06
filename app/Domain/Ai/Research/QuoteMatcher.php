<?php

namespace App\Domain\Ai\Research;

use Normalizer;

/**
 * Checks whether a quoted passage appears on a fetched page. Both sides are
 * normalised (Unicode compatibility form, case, quotes and dashes,
 * punctuation, whitespace); an exact normalised substring scores 1.0,
 * otherwise the best token-overlap ratio of any window of the page around
 * the quote's tokens is used (tolerates small differences such as removed
 * words, line-break hyphenation or footnote markers).
 */
final class QuoteMatcher
{
    public const THRESHOLD = 0.85;

    private const MAX_QUOTE_TOKENS = 80;

    public function matches(string $quote, string $page, float $threshold = self::THRESHOLD): bool
    {
        return $this->score($quote, $page) >= $threshold;
    }

    public function score(string $quote, string $page): float
    {
        $needle = self::normalize($quote);
        $haystack = self::normalize($page);
        if ($needle === '' || $haystack === '') {
            return 0.0;
        }

        if (str_contains($haystack, $needle)) {
            return 1.0;
        }

        $quoteTokens = array_slice(explode(' ', $needle), 0, self::MAX_QUOTE_TOKENS);
        $n = count($quoteTokens);
        if ($n < 3) {
            return 0.0; // too short to match fuzzily with any confidence
        }

        $pageTokens = explode(' ', $haystack);
        $wanted = array_count_values($quoteTokens);
        $window = $n + 2;
        $best = 0.0;
        $total = count($pageTokens);

        // Anchor windows on the quote's distinctive tokens (long words or numbers),
        // aligned so the window starts where the quote would start.
        $firstIndex = [];
        foreach ($quoteTokens as $position => $token) {
            $firstIndex[$token] ??= $position;
        }
        $anchors = array_filter($firstIndex, fn ($position, $token) => mb_strlen((string) $token) >= 4 || is_numeric($token), ARRAY_FILTER_USE_BOTH) ?: $firstIndex;

        for ($i = 0; $i < $total; $i++) {
            if (! isset($anchors[$pageTokens[$i]])) {
                continue;
            }

            $start = max(0, $i - $anchors[$pageTokens[$i]]);
            $counts = array_count_values(array_slice($pageTokens, $start, $window));
            $hits = 0;
            foreach ($wanted as $token => $needed) {
                $hits += min($needed, $counts[$token] ?? 0);
            }

            $best = max($best, $hits / $n);
            if ($best >= 0.999) {
                break;
            }
        }

        return round($best, 3);
    }

    public static function normalize(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        }

        $text = mb_strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = str_replace(['’', '‘', '“', '”', '–', '—', "\u{00A0}", "\u{00AD}"], ["'", "'", '"', '"', '-', '-', ' ', ''], $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
