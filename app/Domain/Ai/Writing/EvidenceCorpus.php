<?php

namespace App\Domain\Ai\Writing;

/**
 * Everything the document is allowed to rely on (the applicant's answers and
 * documents, profile facts and quotes, verified research claims, order
 * details, resolved requirements, a revision request), normalised for the
 * deterministic fact checks.
 */
final class EvidenceCorpus
{
    private const NUMBER_WORDS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8,
        'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15,
        'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20, 'thirty' => 30,
        'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90, 'hundred' => 100,
        'first' => 1, 'second' => 2, 'third' => 3,
    ];

    private string $haystack;

    /** @var array<string, true> */
    private array $numbers = [];

    /** @param list<string> $texts */
    public function __construct(array $texts)
    {
        $joined = implode("\n", array_filter(array_map('strval', $texts), fn ($t) => trim($t) !== ''));
        $this->haystack = self::normalizeText($joined);

        foreach (self::extractNumbers($joined) as $number) {
            $this->numbers[$number['value']] = true;
        }
        foreach (self::NUMBER_WORDS as $word => $value) {
            if (preg_match('/\b'.$word.'\b/u', $this->haystack)) {
                $this->numbers[(string) $value] = true;
            }
        }
    }

    public function hasNumber(string $value): bool
    {
        return isset($this->numbers[$value]);
    }

    public function contains(string $phrase): bool
    {
        $needle = self::normalizeText($phrase);

        return $needle !== '' && str_contains($this->haystack, $needle);
    }

    /**
     * Numbers written with digits: years, integers (with thousands
     * separators), decimals, percentages and ordinals.
     *
     * @return list<array{raw:string, value:string}>
     */
    public static function extractNumbers(string $text): array
    {
        preg_match_all('/(?<![\p{L}\p{N}.,])(\d{1,3}(?:,\d{3})+|\d+(?:\.\d+)?)(\s?(?:%|per\s?cent|percent))?/iu', $text, $matches, PREG_SET_ORDER);

        $numbers = [];
        foreach ($matches as $match) {
            $value = self::normalizeNumber($match[1]);
            if ($value !== null) {
                $numbers[] = ['raw' => trim($match[0]), 'value' => $value];
            }
        }

        return $numbers;
    }

    public static function normalizeNumber(string $raw): ?string
    {
        $value = str_replace(',', '', trim($raw));
        if (! is_numeric($value)) {
            return null;
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, null);
        $integer = ltrim((string) $integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = $fraction !== null ? rtrim($fraction, '0') : '';

        return $fraction !== '' ? $integer.'.'.$fraction : $integer;
    }

    public static function normalizeText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(['’', '‘', '“', '”', '–', '—'], ["'", "'", '"', '"', '-', '-'], $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
