<?php

namespace App\Domain\Ai\Samples;

use App\Domain\Ai\Writing\SentenceSplitter;
use App\Domain\Documents\DocumentModel;

/**
 * Finds paragraph sentences of a draft that repeat a writing sample's
 * wording: any run of WINDOW consecutive words that also occurs in a sample.
 * Samples are style references only, so such a sentence is either plagiarism
 * or another person's facts. Words of the order's own institution and
 * programme names break a run, since a sample for the same programme may
 * legitimately name them. Only paragraphs are checked: they are what the
 * factual review can rewrite or remove.
 */
final class WritingSampleOverlap
{
    public const COPIED_FROM_SAMPLE = 'copied_from_sample';

    /** Consecutive shared words that count as copying (long enough to skip stock phrases). */
    public const WINDOW = 12;

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
            foreach ($this->shingles($text, $ignored) as $shingle) {
                $known[$shingle] = true;
            }
        }

        if ($known === []) {
            return [];
        }

        $findings = [];
        foreach ($draft->blocks as $block) {
            if ($block['type'] !== 'paragraph') {
                continue;
            }

            foreach (SentenceSplitter::split($block['text']) as $sentence) {
                foreach ($this->shingles($sentence, $ignored) as $shingle) {
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
     * Every run of WINDOW consecutive words; an ignored word ends a run.
     *
     * @param  array<string, true>  $ignored
     * @return list<string>
     */
    private function shingles(string $text, array $ignored): array
    {
        $shingles = [];
        $run = [];
        foreach ([...SentenceSplitter::words(str_replace(['’', '‘'], "'", $text)), null] as $word) {
            if ($word !== null && ! isset($ignored[$word])) {
                $run[] = $word;

                continue;
            }

            for ($i = 0, $last = count($run) - self::WINDOW; $i <= $last; $i++) {
                $shingles[] = implode(' ', array_slice($run, $i, self::WINDOW));
            }
            $run = [];
        }

        return $shingles;
    }
}
