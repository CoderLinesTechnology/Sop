<?php

namespace App\Domain\Ai\Writing;

use App\Domain\Documents\DocumentModel;

/**
 * Deterministic factual checks that never depend on a model:
 *  - every number, year and percentage must appear in the evidence;
 *  - institution-like and degree-like names must be the order's institution /
 *    programme or appear in the applicant's material or verified research;
 *  - no URLs or citations unless the requirements allow them;
 *  - no template placeholders.
 *
 * Findings feed the LLM fact-fix pass; sanitize() is the last-resort
 * fallback that drops sentences whose problem is mechanical (numbers, URLs,
 * placeholders).
 */
final class FactChecker
{
    public const UNSUPPORTED_NUMBER = 'unsupported_number';

    public const URL_OR_CITATION = 'url_or_citation';

    public const PLACEHOLDER = 'placeholder';

    public const UNKNOWN_INSTITUTION = 'unknown_institution';

    public const UNKNOWN_PROGRAMME = 'unknown_programme';

    /** Problem types sanitize() may resolve by removing the sentence. */
    /** An email address or international phone number: contact details never belong in the document. */
    public const CONTACT_DETAILS = 'contact_details';

    public const REMOVABLE = [self::UNSUPPORTED_NUMBER, self::URL_OR_CITATION, self::PLACEHOLDER, self::CONTACT_DETAILS];

    private const INSTITUTION_KEYWORDS = 'University|College|Institute|Polytechnic|Academy|Conservatoire|Conservatory|School';

    private const LEADING_STOPWORDS = [
        'the', 'a', 'an', 'my', 'our', 'your', 'their', 'his', 'her', 'its', 'this', 'that', 'these', 'those', 'each',
        'every', 'during', 'in', 'at', 'from', 'to', 'for', 'while', 'when', 'after', 'before', 'since', 'because',
        'as', 'and', 'but', 'or', 'of', 'with', 'by', 'on', 'into', 'through', 'both', 'any', 'some', 'graduate',
        'undergraduate', 'secondary', 'high', 'primary', 'medical', 'business', 'law', 'summer', 'online', 'local',
    ];

    private const DEGREE_ABBREVIATIONS = 'BSc|BA|BEng|BEd|BBA|BCom|BTech|LLB|MBBS|MSc|MA|MEng|MBA|MPhil|MRes|MPH|MPA|MFA|LLM|MEd|MTech|PhD|DPhil|EdD|DBA';

    /**
     * @param  array{institution?:?string, programme?:?string}  $order
     * @return list<array{type:string, severity:string, excerpt:string, problem:string}>
     */
    public function check(DocumentModel $document, EvidenceCorpus $evidence, array $order, bool $citationsAllowed = false): array
    {
        $issues = [];

        foreach ($document->blocks as $block) {
            foreach (SentenceSplitter::split($block['text']) as $sentence) {
                foreach (EvidenceCorpus::extractNumbers($sentence) as $number) {
                    if (! $evidence->hasNumber($number['value'])) {
                        $issues[] = $this->issue(self::UNSUPPORTED_NUMBER, $sentence, "The number \"{$number['raw']}\" does not appear in the applicant's material, the order or the verified research.");
                    }
                }

                if (! $citationsAllowed && preg_match('~https?://|\bwww\.|\bdoi:|\[\d{1,3}\]|\((?:[A-Z][\p{L}-]+(?: et al\.?)?(?: (?:and|&) [A-Z][\p{L}-]+)?), \d{4}\)~u', $sentence)) {
                    $issues[] = $this->issue(self::URL_OR_CITATION, $sentence, 'URLs, citations and references are not allowed in this document.');
                }

                if (preg_match('/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.\p{L}{2,}|\+\d[\d\s().-]{7,}\d/u', $sentence)) {
                    $issues[] = $this->issue(self::CONTACT_DETAILS, $sentence, 'Contact details (an email address or phone number) do not belong in the document.');
                }

                if (preg_match('/\[(?:your|insert|name|applicant|university|institution|programme|program|course|date|x{2,})[^\]]{0,40}\]|\{\{|\}\}|lorem ipsum|\bTBD\b|\bXXX+\b/iu', $sentence)) {
                    $issues[] = $this->issue(self::PLACEHOLDER, $sentence, 'The sentence contains placeholder text.');
                }

                foreach ($this->institutionNames($sentence) as $name) {
                    if (! $this->isKnownName($name, (string) ($order['institution'] ?? ''), $evidence)) {
                        $issues[] = $this->issue(self::UNKNOWN_INSTITUTION, $sentence, "\"{$name}\" is neither the institution in the order nor an organisation found in the applicant's material or the verified research.");
                    }
                }

                foreach ($this->degreeNames($sentence) as $name) {
                    if (! $this->isKnownName($name, (string) ($order['programme'] ?? ''), $evidence)) {
                        $issues[] = $this->issue(self::UNKNOWN_PROGRAMME, $sentence, "\"{$name}\" is neither the programme in the order nor a qualification in the applicant's material.");
                    }
                }
            }
        }

        // One finding per (type, sentence).
        $unique = [];
        foreach ($issues as $issue) {
            $unique[$issue['type'].'|'.$issue['excerpt']] ??= $issue;
        }

        return array_values($unique);
    }

    /**
     * Remove sentences containing removable problems. Blocks left empty are
     * dropped (headings are kept).
     *
     * @param  list<array{type:string, excerpt:string}>  $issues
     */
    public function sanitize(DocumentModel $document, array $issues): DocumentModel
    {
        $excerpts = [];
        foreach ($issues as $issue) {
            if (in_array($issue['type'], self::REMOVABLE, true)) {
                $excerpts[] = $issue['excerpt'];
            }
        }
        if ($excerpts === []) {
            return $document;
        }

        $blocks = [];
        foreach ($document->blocks as $block) {
            if ($block['type'] !== 'paragraph') {
                $blocks[] = $block;

                continue;
            }

            $kept = array_filter(SentenceSplitter::split($block['text']), fn (string $s) => ! in_array($s, $excerpts, true));
            if ($kept !== []) {
                $blocks[] = ['type' => 'paragraph', 'text' => implode(' ', $kept)];
            }
        }

        return new DocumentModel($document->title, $document->subtitle, $document->applicantName, $blocks, $document->languageVariant, $document->date);
    }

    /** @return list<string> */
    public function institutionNames(string $sentence): array
    {
        $keywords = self::INSTITUTION_KEYWORDS;
        $word = "[\\p{Lu}][\\p{L}&'’.-]*";
        $names = [];

        // "University of X", "Institute of X and Y"
        if (preg_match_all("/\\b(?:{$keywords})\\s+(?:of|for)\\s+(?:the\\s+)?{$word}(?:\\s+(?:and\\s+|&\\s+|of\\s+|for\\s+|the\\s+)?{$word})*/u", $sentence, $m)) {
            $names = [...$names, ...$m[0]];
        }
        // "Ashesi University", "Imperial College London", "X Institute of Technology"
        if (preg_match_all("/(?:{$word}\\s+){1,4}(?:{$keywords})\\b(?:\\s+(?!of\\b){$word})?/u", $sentence, $m)) {
            foreach ($m[0] as $candidate) {
                $stripped = $this->stripLeadingStopwords($candidate);
                if ($stripped !== null) {
                    $names[] = $stripped;
                }
            }
        }

        return array_values(array_unique(array_map('trim', $names)));
    }

    /** @return list<string> */
    public function degreeNames(string $sentence): array
    {
        $abbr = self::DEGREE_ABBREVIATIONS;
        $word = '[\\p{Lu}][\\p{L}&-]*';
        $names = [];

        if (preg_match_all("/\\b(?:{$abbr})\\b(?:\\s*\\(Hons\\))?\\s+(?:in\\s+)?{$word}(?:\\s+(?:and\\s+|&\\s+|of\\s+|in\\s+)?{$word})*/u", $sentence, $m)) {
            $names = [...$names, ...$m[0]];
        }
        if (preg_match_all("/\\b(?:Bachelor|Master|Doctor)(?:'s|’s)?\\s+of\\s+{$word}(?:\\s+(?:in\\s+|and\\s+|of\\s+)?{$word})*/u", $sentence, $m)) {
            $names = [...$names, ...$m[0]];
        }

        return array_values(array_unique(array_map('trim', $names)));
    }

    private function isKnownName(string $name, string $orderValue, EvidenceCorpus $evidence): bool
    {
        if ($evidence->contains($name)) {
            return true;
        }

        $distinctive = $this->distinctiveWords($name);
        if ($distinctive === []) {
            return true; // nothing specific to check ("the University")
        }

        $reference = $this->distinctiveWords($orderValue);
        if ($reference !== [] && array_diff($distinctive, $reference) === []) {
            return true; // e.g. "Oxford University" for "University of Oxford"
        }

        // All distinctive words appear together somewhere in the evidence.
        return $evidence->contains(implode(' ', $distinctive));
    }

    /** @return list<string> */
    private function distinctiveWords(string $value): array
    {
        $generic = ['university', 'college', 'institute', 'polytechnic', 'academy', 'conservatoire', 'conservatory', 'school',
            'of', 'the', 'and', 'for', 'in', 'technology', 'msc', 'ma', 'ba', 'bsc', 'mba', 'phd', 'mphil', 'mres', 'meng',
            'beng', 'llm', 'llb', 'master', "master's", 'masters', 'bachelor', "bachelor's", 'doctor', 'hons', 'degree', 'programme', 'program'];

        $words = SentenceSplitter::words(str_replace(['&', '(', ')'], ' ', $value));

        return array_values(array_diff($words, $generic));
    }

    private function stripLeadingStopwords(string $candidate): ?string
    {
        $words = preg_split('/\s+/u', trim($candidate)) ?: [];
        while ($words !== [] && in_array(mb_strtolower($words[0]), self::LEADING_STOPWORDS, true)) {
            array_shift($words);
        }

        if (count($words) < 2) {
            return null; // only the keyword itself is left
        }

        return implode(' ', $words);
    }

    private function issue(string $type, string $excerpt, string $problem): array
    {
        return ['type' => $type, 'severity' => 'high', 'excerpt' => $excerpt, 'problem' => $problem];
    }
}
