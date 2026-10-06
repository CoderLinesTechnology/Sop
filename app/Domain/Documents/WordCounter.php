<?php

namespace App\Domain\Documents;

/**
 * Counts words and characters the way word processors and application
 * portals do: words are whitespace-separated tokens containing at least one
 * letter or digit; characters are Unicode code points (with or without
 * whitespace). Line breaks count as one character, as in most portals.
 */
final class WordCounter
{
    public static function words(string $text): int
    {
        $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count(array_filter($tokens, fn (string $t) => preg_match('/[\pL\pN]/u', $t) === 1));
    }

    public static function characters(string $text, bool $withSpaces = true): int
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $text = preg_replace("/\n{2,}/", "\n", $text) ?? $text;

        if (! $withSpaces) {
            $text = preg_replace('/\s+/u', '', $text) ?? $text;
        }

        return mb_strlen($text);
    }
}
