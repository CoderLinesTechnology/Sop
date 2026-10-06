<?php

namespace App\Domain\Documents\Qa;

use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\ResolvedRequirements;
use App\Domain\Documents\WordCounter;

/**
 * Re-checks a version's length against its resolved requirements: total
 * words and characters (counted the way the platform counts them) and the
 * limits of each required section, e.g. UCAS's 350-character minimum per
 * answer.
 */
final class LengthCheck
{
    /** @return list<array{check:string,passed:bool,detail:string}> */
    public static function run(DocumentModel $model, ResolvedRequirements $requirements): array
    {
        $words = $requirements->countWords($model);
        $characters = $requirements->countCharacters($model);
        $scope = $requirements->limitsIncludeHeadings ? '' : ' (answers only; questions not counted)';

        return [
            self::range('word_limits', 'words', $words, $requirements->minWords, $requirements->maxWords, $scope),
            self::range('character_limits', 'characters including spaces', $characters, $requirements->minCharacters, $requirements->maxCharacters, $scope),
            self::sections($model, $requirements),
        ];
    }

    private static function range(string $check, string $unit, int $value, ?int $min, ?int $max, string $scope): array
    {
        if ($min === null && $max === null) {
            return ['check' => $check, 'passed' => true, 'detail' => "No {$unit} limit applies; the document has {$value} {$unit}{$scope}."];
        }

        $passed = ($min === null || $value >= $min) && ($max === null || $value <= $max);
        $limit = match (true) {
            $min !== null && $max !== null => "{$min}–{$max}",
            $max !== null => "at most {$max}",
            default => "at least {$min}",
        };

        return [
            'check' => $check,
            'passed' => $passed,
            'detail' => ($passed ? 'Within' : 'Outside')." the limit of {$limit} {$unit}: {$value}{$scope}.",
        ];
    }

    private static function sections(DocumentModel $model, ResolvedRequirements $requirements): array
    {
        // Only sections the platform asks for verbatim, or that carry their own limits, are enforced.
        $required = array_values(array_filter($requirements->requiredSections, fn ($s) => is_array($s) && (
            filled($s['question'] ?? null) || isset($s['min_characters']) || isset($s['max_characters']) || isset($s['min_words']) || isset($s['max_words'])
        )));
        if ($required === []) {
            return ['check' => 'section_limits', 'passed' => true, 'detail' => 'No section-level requirements apply.'];
        }

        $sections = array_values(array_filter($model->sections(), fn ($s) => $s['heading'] !== null));
        $problems = [];
        $details = [];

        foreach ($required as $position => $rule) {
            $section = self::match($rule, $sections, $position, count($required));
            $label = (string) ($rule['heading'] ?? $rule['question'] ?? 'Section '.($position + 1));
            if ($section === null) {
                $problems[] = "missing section “{$label}”";

                continue;
            }

            $chars = WordCounter::characters($section['text']);
            $words = WordCounter::words($section['text']);
            foreach ([
                ['min_characters', $chars, '>=', 'characters'], ['max_characters', $chars, '<=', 'characters'],
                ['min_words', $words, '>=', 'words'], ['max_words', $words, '<=', 'words'],
            ] as [$key, $value, $operator, $unit]) {
                if (! isset($rule[$key])) {
                    continue;
                }
                $limit = (int) $rule[$key];
                if ($operator === '>=' ? $value < $limit : $value > $limit) {
                    $problems[] = "“{$label}” has {$value} {$unit} (".($operator === '>=' ? 'minimum' : 'maximum')." {$limit})";
                }
            }
            $details[] = "{$chars} characters";
        }

        return [
            'check' => 'section_limits',
            'passed' => $problems === [],
            'detail' => $problems === []
                ? 'All '.count($required).' required sections are present and within their limits ('.implode(', ', $details).').'
                : 'Section requirements not met: '.implode('; ', $problems).'.',
        ];
    }

    /** Find a required section by its heading or question text; fall back to position when counts agree. */
    private static function match(array $rule, array $sections, int $position, int $requiredCount): ?array
    {
        $wanted = array_filter([self::key($rule['heading'] ?? null), self::key($rule['question'] ?? null)]);
        foreach ($sections as $section) {
            if (in_array(self::key($section['heading']), $wanted, true)) {
                return $section;
            }
        }

        return count($sections) === $requiredCount ? $sections[$position] : null;
    }

    private static function key(?string $text): string
    {
        return trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower((string) $text)) ?? '');
    }
}
