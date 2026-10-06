<?php

namespace App\Domain\Ai\Writing;

use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\ResolvedRequirements;
use App\Domain\Documents\WordCounter;
use App\Models\DocumentTemplate;

/**
 * Deterministic length and structure checks against the resolved
 * requirements: words, characters (with spaces), estimated pages, required
 * section headings and per-section limits. When no hard limit exists, a soft
 * band around the target length keeps documents reasonable.
 *
 * Counting uses WordCounter / DocumentModel exactly as the portals do; the
 * page count is an estimate (the rendered PDF is checked again in file QA).
 */
final class LengthChecker
{
    /** Words per A4 page at 12pt, single spacing, 25.4 mm margins. */
    private const WORDS_PER_PAGE_SINGLE = 580;

    /**
     * @return array{
     *   ok: bool, hard: bool,
     *   violations: list<array{type:string, limit:int, actual:int, section:?string, message:string}>,
     *   counts: array{words:int, characters:int, characters_no_spaces:int, estimated_pages:float},
     *   targets: array{words:?int, characters:?int}
     * }
     */
    public function check(DocumentModel $document, ResolvedRequirements $requirements, ?DocumentTemplate $template, int $targetWords): array
    {
        $words = $document->wordCount();
        $characters = $document->characterCount(true);
        $pages = $this->estimatePages($words, $requirements, $template);
        $violations = [];

        $check = function (string $type, ?int $limit, int $actual, bool $isMax, ?string $section = null) use (&$violations) {
            if ($limit === null || $limit <= 0) {
                return;
            }
            if ($isMax ? $actual > $limit : $actual < $limit) {
                $unit = str_contains($type, 'characters') ? 'characters' : 'words';
                $where = $section ? " in section \"{$section}\"" : '';
                $violations[] = [
                    'type' => $type, 'limit' => $limit, 'actual' => $actual, 'section' => $section,
                    'message' => ($isMax ? 'Too long' : 'Too short')."{$where}: {$actual} {$unit} (".($isMax ? 'maximum' : 'minimum')." {$limit}).",
                ];
            }
        };

        $check('max_words', $requirements->maxWords, $words, true);
        $check('min_words', $requirements->minWords, $words, false);
        $check('max_characters', $requirements->maxCharacters, $characters, true);
        $check('min_characters', $requirements->minCharacters, $characters, false);

        if ($requirements->maxPages && $pages > $requirements->maxPages) {
            $violations[] = [
                'type' => 'max_pages', 'limit' => $requirements->maxPages, 'actual' => (int) ceil($pages), 'section' => null,
                'message' => sprintf('Too long: about %.1f pages (maximum %d).', $pages, $requirements->maxPages),
            ];
        }

        foreach ($this->sectionViolations($document, $requirements) as $violation) {
            $violations[] = $violation;
        }

        $hard = $violations !== [];
        $hasHardLimit = $requirements->maxWords || $requirements->minWords || $requirements->maxCharacters
            || $requirements->minCharacters || $requirements->maxPages;

        // Soft band only when nothing official constrains the length.
        if (! $hasHardLimit && $targetWords > 0 && ($words > $targetWords * 1.3 || $words < $targetWords * 0.6)) {
            $violations[] = [
                'type' => 'target_words', 'limit' => $targetWords, 'actual' => $words, 'section' => null,
                'message' => "Length {$words} words is far from the target of about {$targetWords} words.",
            ];
        }

        return [
            'ok' => $violations === [],
            'hard' => $hard,
            'violations' => $violations,
            'counts' => [
                'words' => $words,
                'characters' => $characters,
                'characters_no_spaces' => $document->characterCount(false),
                'estimated_pages' => round($pages, 2),
            ],
            'targets' => $this->targets($requirements, $template, $targetWords, $words),
        ];
    }

    /** @return array{words:?int, characters:?int} */
    public function targets(ResolvedRequirements $requirements, ?DocumentTemplate $template, int $targetWords, int $currentWords): array
    {
        $words = $targetWords > 0 ? $targetWords : null;

        if ($requirements->maxWords) {
            $words = min($words ?? PHP_INT_MAX, (int) floor($requirements->maxWords * 0.95));
        }
        if ($requirements->minWords) {
            $words = max($words ?? 0, (int) ceil($requirements->minWords * 1.05));
            if ($requirements->maxWords) {
                $words = min($words, $requirements->maxWords);
            }
        }
        if ($requirements->maxPages) {
            $perPage = $this->wordsPerPage($requirements, $template);
            $words = min($words ?? PHP_INT_MAX, (int) floor(($requirements->maxPages - 0.2) * $perPage * 0.92));
        }

        $characters = null;
        if ($requirements->maxCharacters) {
            $characters = (int) floor($requirements->maxCharacters * 0.96);
            // ~6.2 characters per English word including the following space.
            $words = min($words ?? PHP_INT_MAX, (int) floor($characters / 6.2));
        }
        if ($requirements->minCharacters) {
            $characters = max($characters ?? 0, (int) ceil($requirements->minCharacters * 1.04));
        }

        return ['words' => $words === PHP_INT_MAX ? null : $words, 'characters' => $characters];
    }

    public function estimatePages(int $words, ResolvedRequirements $requirements, ?DocumentTemplate $template): float
    {
        return 0.2 + $words / max(100, $this->wordsPerPage($requirements, $template));
    }

    private function wordsPerPage(ResolvedRequirements $requirements, ?DocumentTemplate $template): float
    {
        $fontSize = (float) ($requirements->fontSize ?? $template?->font_size ?? 12);
        $lineSpacing = (float) ($requirements->lineSpacing ?? $template?->line_spacing ?? 1.5);
        $margin = (float) ($requirements->marginsMm ?? $template?->margin_left_mm ?? 25.4);

        $areaRatio = max(0.3, ((210 - 2 * $margin) * (297 - 2 * $margin)) / ((210 - 50.8) * (297 - 50.8)));

        return self::WORDS_PER_PAGE_SINGLE / max(0.8, $lineSpacing) * (12 / max(8, $fontSize)) ** 2 * $areaRatio;
    }

    /** @return list<array{type:string, limit:int, actual:int, section:?string, message:string}> */
    private function sectionViolations(DocumentModel $document, ResolvedRequirements $requirements): array
    {
        if ($requirements->requiredSections === []) {
            return [];
        }

        $sections = $this->sections($document);
        $violations = [];

        foreach ($requirements->requiredSections as $required) {
            $heading = trim((string) ($required['heading'] ?? ''));
            if ($heading === '') {
                continue;
            }
            $key = $this->headingKey($heading);

            if (! array_key_exists($key, $sections)) {
                $violations[] = ['type' => 'missing_section', 'limit' => 1, 'actual' => 0, 'section' => $heading, 'message' => "Required section \"{$heading}\" is missing (use the exact heading)."];

                continue;
            }

            $body = $sections[$key];
            $sectionWords = WordCounter::words($body);
            $sectionChars = WordCounter::characters($body);

            foreach ([
                ['section_max_words', $required['max_words'] ?? null, $sectionWords, true],
                ['section_min_words', $required['min_words'] ?? null, $sectionWords, false],
                ['section_max_characters', $required['max_characters'] ?? null, $sectionChars, true],
                ['section_min_characters', $required['min_characters'] ?? null, $sectionChars, false],
            ] as [$type, $limit, $actual, $isMax]) {
                $limit = $limit !== null ? (int) $limit : null;
                if ($limit && ($isMax ? $actual > $limit : $actual < $limit)) {
                    $unit = str_contains($type, 'characters') ? 'characters' : 'words';
                    $violations[] = [
                        'type' => $type, 'limit' => $limit, 'actual' => $actual, 'section' => $heading,
                        'message' => ($isMax ? 'Too long' : 'Too short')." in section \"{$heading}\": {$actual} {$unit} (".($isMax ? 'maximum' : 'minimum')." {$limit}).",
                    ];
                }
            }
        }

        return $violations;
    }

    /** @return array<string, string> heading key => body text */
    private function sections(DocumentModel $document): array
    {
        $sections = [];
        $current = null;
        foreach ($document->blocks as $block) {
            if ($block['type'] === 'heading') {
                $current = $this->headingKey($block['text']);
                $sections[$current] = '';

                continue;
            }
            if ($current !== null) {
                $sections[$current] = trim($sections[$current]."\n".$block['text']);
            }
        }

        return $sections;
    }

    private function headingKey(string $heading): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($heading)) ?? '');
    }
}
