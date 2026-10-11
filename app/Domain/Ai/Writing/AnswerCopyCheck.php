<?php

namespace App\Domain\Ai\Writing;

use App\Domain\Documents\DocumentModel;

/**
 * Finds draft sentences (paragraphs and bullets) that repeat the customer's own
 * answers almost word for word: any run of WINDOW consecutive words that also
 * occurs in an answer. Customers pay for polished writing, so such sentences go
 * to the editorial pass to be rewritten, keeping every fact, name and number.
 * Deterministic: no model call.
 */
final class AnswerCopyCheck
{
    public const TYPE = 'copied_answer';

    /** Consecutive shared words that count as copying (long enough to skip names and stock phrases). */
    public const WINDOW = 8;

    private const MAX_FINDINGS = 12;

    /**
     * @param  list<string>  $answers  the customer's answer texts
     * @return list<array{type:string, severity:string, message:string, excerpt:?string}>
     */
    public function find(DocumentModel $draft, array $answers): array
    {
        $known = [];
        foreach ($answers as $answer) {
            foreach ($this->shingles((string) $answer) as $shingle) {
                $known[$shingle] = true;
            }
        }
        if ($known === []) {
            return [];
        }

        $findings = [];
        foreach ($draft->blocks as $block) {
            if (! DocumentModel::isBody($block['type'])) {
                continue;
            }
            foreach (SentenceSplitter::split($block['text']) as $sentence) {
                foreach ($this->shingles($sentence) as $shingle) {
                    if (isset($known[$shingle])) {
                        $findings[] = [
                            'type' => self::TYPE,
                            'severity' => 'medium',
                            'message' => "This repeats the customer's own answer almost word for word. Rewrite it as polished, professional writing, keeping every fact, name and number exact.",
                            'excerpt' => $sentence,
                        ];
                        if (count($findings) >= self::MAX_FINDINGS) {
                            return $findings;
                        }

                        break;
                    }
                }
            }
        }

        return $findings;
    }

    /** @return list<string> every run of WINDOW consecutive lower-cased words */
    private function shingles(string $text): array
    {
        $words = array_map(fn (string $w) => mb_strtolower($w), SentenceSplitter::words(str_replace(['’', '‘'], "'", $text)));
        $shingles = [];
        for ($i = 0, $last = count($words) - self::WINDOW; $i <= $last; $i++) {
            $shingles[] = implode(' ', array_slice($words, $i, self::WINDOW));
        }

        return $shingles;
    }
}
