<?php

namespace App\Domain\Ai\Samples;

use App\Domain\Ai\Writing\SentenceSplitter;
use App\Domain\Documents\DocumentModel;

/**
 * Finds sentences of a draft that repeat a writing sample's wording: any run
 * of WINDOW consecutive words that also occurs in a sample. Samples are style
 * references only, so such a sentence is either plagiarism or another
 * person's facts. The order's own institution and programme names are
 * ignored, since a sample for the same programme may legitimately name them.
 */
final class WritingSampleOverlap
{
    public const COPIED_FROM_SAMPLE = 'copied_from_sample';

    /** Consecutive shared words that count as copying. */
    public const WINDOW = 10;

    /** Name words shorter than this are kept ("of", "and", "in"). */
    private const MIN_IGNORED_WORD = 4;

    /**
     * @param  list<string>  $sampleTexts
     * @param  list<?string>  $ownNames  the order's institution and programme
     * @return list<array{type:string, severity:string, excerpt:string, problem:string, fix:string}>
     */
    public function find(DocumentModel $draft, array $sampleTexts, array $ownNames = []): array
    {
        $ignored = [];
        foreach ($ownNames as $name) {
            foreach (SentenceSplitter::words((string) $name) as $word) {
                if (mb_strlen($word) >= self::MIN_IGNORED_WORD) {
                    $ignored[$word] = true;
                }
            }
        }

        $known = [];
        foreach ($sampleTexts as $text) {
            foreach ($this->shingles($this->words($text, $ignored)) as $shingle) {
                $known[$shingle] = true;
            }
        }

        if ($known === []) {
            return [];
        }

        $findings = [];
        foreach ($draft->blocks as $block) {
            foreach (SentenceSplitter::split($block['text']) as $sentence) {
                foreach ($this->shingles($this->words($sentence, $ignored)) as $shingle) {
                    if (isset($known[$shingle])) {
                        $findings[] = [
                            'type' => self::COPIED_FROM_SAMPLE,
                            'severity' => 'high',
                            'excerpt' => $sentence,
                            'problem' => 'This sentence repeats the wording of a writing sample. Samples are style references only; nothing may be copied from them.',
                            'fix' => "Rewrite the sentence in new words using only the applicant's own material, or remove it.",
                        ];
                        break;
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, true>  $ignored
     * @return list<string>
     */
    private function words(string $text, array $ignored): array
    {
        return array_values(array_filter(
            SentenceSplitter::words(str_replace(['’', '‘'], "'", $text)),
            fn (string $word) => ! isset($ignored[$word]),
        ));
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private function shingles(array $words): array
    {
        $shingles = [];
        for ($i = 0, $last = count($words) - self::WINDOW; $i <= $last; $i++) {
            $shingles[] = implode(' ', array_slice($words, $i, self::WINDOW));
        }

        return $shingles;
    }
}
