<?php

namespace App\Domain\Ai\Writing;

use App\Domain\Ai\Prompts\LanguageGuide;
use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\WordCounter;
use App\Enums\DocumentKind;

/**
 * Deterministic style checks whose findings feed the editorial pass:
 * banned phrases (Settings ai.banned_phrases), em-dash overuse, runs of
 * semicolons, ellipses, self-praise without evidence, repeated sentence
 * openings, monotonous sentence length, overlong sentences, stock
 * transitions, exclamation marks and spelling that does not match the
 * required English variant. The goal is authentic, specific writing, not
 * gaming AI detectors.
 */
final class StyleLinter
{
    private const STOCK_TRANSITIONS = ['furthermore', 'moreover', 'additionally', 'in conclusion', 'overall', 'ultimately', 'in summary', 'to conclude', 'notably', 'importantly'];

    /** Em dashes allowed in a whole document: they should be rare. */
    private const MAX_DASHES = 1;

    /** Plain, human rhythm: no sentence above this many words, an average no higher than AVERAGE_WORDS. */
    private const LONG_SENTENCE_WORDS = 30;

    private const AVERAGE_WORDS = 20;

    /** Average letters per word above which the wording reads heavy and abstract. */
    private const AVERAGE_WORD_LENGTH = 5.4;

    /** Short words (the, I, to, it, that...) carry natural speech; too few makes prose dense. */
    private const MIN_FUNCTION_WORD_SHARE = 0.40;

    private const FUNCTION_WORDS = [
        'a', 'an', 'the', 'and', 'or', 'but', 'so', 'if', 'because', 'as', 'of', 'in', 'on', 'at', 'to', 'for', 'with',
        'from', 'by', 'about', 'into', 'through', 'over', 'after', 'before', 'between', 'during', 'without', 'within',
        'i', 'me', 'my', 'we', 'our', 'you', 'your', 'he', 'she', 'it', 'its', 'they', 'them', 'their', 'this', 'that',
        'these', 'those', 'who', 'which', 'what', 'when', 'where', 'how', 'why', 'is', 'am', 'are', 'was', 'were', 'be',
        'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'can', 'could', 'should', 'may',
        'might', 'must', 'not', 'no', 'also', 'than', 'then', 'there', 'here', 'very', 'just', 'more', 'most', 'some',
        'any', 'all', 'each', 'both', 'one', 'up', 'out',
    ];

    /** Words and labels that make prose read machine-written; each has an everyday alternative. */
    private const STIFF_WORDS = [
        'meaningful', 'meaningfully', 'demonstrably', 'foundational', 'trajectory', 'methodological', 'interplay',
        'nuanced', 'robust', 'leverage', 'leveraging', 'multifaceted', 'underscore', 'underscores', 'showcase',
        'intricate', 'holistic', 'seamless', 'seamlessly', 'invaluable', 'profound', 'endeavor', 'endeavour',
        'landscape', 'harness', 'spearhead', 'transformative', 'cultivate', 'facilitate', 'utilize', 'utilise',
        'furthermore', 'particularly interested', 'i am seeking', 'i hope to develop', 'would help me',
        'i would also bring', 'aligns with', 'alignment with', 'deepen my', 'broader claim', 'defensible',
    ];

    /** Self-descriptions that claim a quality instead of showing it. */
    private const EMPTY_CLAIMS = [
        'i am passionate about', "i'm passionate about", 'i am deeply passionate', 'my passion for',
        'i am a highly motivated', 'highly motivated individual', 'i am highly motivated',
        'excellent leadership skills', 'strong leadership skills', 'excellent communication skills',
        'innovative and dedicated', 'dedicated and hardworking', 'hard-working and dedicated',
        'i am well prepared', 'i am well-prepared', 'i am the ideal candidate', 'i am a perfect fit',
        'i am a quick learner', 'i am a team player', 'eager to learn', 'willingness to learn',
    ];

    /** british => american pairs; grouped by the variant preference that decides them. */
    private const SPELLINGS = [
        'our' => ['colour' => 'color', 'colours' => 'colors', 'favour' => 'favor', 'favourite' => 'favorite', 'behaviour' => 'behavior', 'behaviours' => 'behaviors', 'honour' => 'honor', 'labour' => 'labor', 'neighbour' => 'neighbor', 'neighbourhood' => 'neighborhood', 'humour' => 'humor', 'endeavour' => 'endeavor', 'rigour' => 'rigor', 'vigour' => 'vigor', 'harbour' => 'harbor'],
        're' => ['centre' => 'center', 'centres' => 'centers', 'theatre' => 'theater', 'fibre' => 'fiber', 'litre' => 'liter'],
        'ise' => ['organise' => 'organize', 'organised' => 'organized', 'organising' => 'organizing', 'organisation' => 'organization', 'organisations' => 'organizations', 'realise' => 'realize', 'realised' => 'realized', 'recognise' => 'recognize', 'recognised' => 'recognized', 'specialise' => 'specialize', 'specialised' => 'specialized', 'specialisation' => 'specialization', 'prioritise' => 'prioritize', 'emphasise' => 'emphasize', 'summarise' => 'summarize', 'utilise' => 'utilize', 'optimise' => 'optimize', 'optimisation' => 'optimization', 'analyse' => 'analyze', 'analysed' => 'analyzed', 'analysing' => 'analyzing', 'minimise' => 'minimize', 'maximise' => 'maximize', 'characterise' => 'characterize', 'apologise' => 'apologize', 'mobilise' => 'mobilize', 'visualise' => 'visualize', 'visualisation' => 'visualization'],
        'll' => ['travelled' => 'traveled', 'travelling' => 'traveling', 'modelling' => 'modeling', 'modelled' => 'modeled', 'labelled' => 'labeled', 'cancelled' => 'canceled', 'enrolment' => 'enrollment', 'fulfil' => 'fulfill', 'counselling' => 'counseling', 'jewellery' => 'jewelry'],
        'programme' => ['programme' => 'program', 'programmes' => 'programs'],
    ];

    /**
     * @param  list<string>  $bannedPhrases
     * @param  list<string>  $protectedTerms  names that must not be "corrected" (institution, programme)
     * @return list<array{type:string, severity:string, message:string, excerpt:?string}>
     */
    /**
     * First-person narrative documents get the plain-style checks (rhythm targets, word length,
     * stiff wording). CVs, research proposals and academic or general essays keep only the
     * long-sentence check: their register legitimately uses technical words and denser prose.
     */
    public const NARRATIVE_KINDS = [
        DocumentKind::PersonalStatement, DocumentKind::StatementOfPurpose, DocumentKind::MotivationLetter,
        DocumentKind::ScholarshipEssay, DocumentKind::CoverLetter,
    ];

    /** Findings the editorial pass exists to fix; at least two of them (or any high one) justify the extra call. */
    public const EDITABLE_TYPES = [
        'long_sentence', 'long_average', 'few_short_sentences', 'monotonous_rhythm', 'long_words', 'stiff_wording',
        'banned_phrase', 'dash_overuse', 'empty_claim', AnswerCopyCheck::TYPE,
    ];

    public function lint(DocumentModel $document, array $bannedPhrases, string $languageVariant = 'en-GB', array $protectedTerms = [], ?DocumentKind $kind = null): array
    {
        $narrative = $kind === null || in_array($kind, self::NARRATIVE_KINDS, true);
        $paragraphs = $document->paragraphs();
        $text = implode("\n\n", $paragraphs);
        $sentences = [];
        foreach ($paragraphs as $paragraph) {
            $sentences = [...$sentences, ...SentenceSplitter::split($paragraph)];
        }

        return [
            ...$this->bannedPhrases($sentences, $bannedPhrases),
            ...$this->dashes($text),
            ...$this->semicolons($text),
            ...$this->ellipses($sentences),
            ...$this->emptyClaims($sentences),
            ...$this->openings($sentences),
            ...$this->rhythm($sentences, $narrative),
            ...($narrative ? $this->plainness($sentences, $protectedTerms) : []),
            ...$this->transitions($sentences),
            ...$this->exclamations($sentences),
            ...$this->spelling($text, $languageVariant, $protectedTerms),
        ];
    }

    /** @param list<string> $sentences */
    private function bannedPhrases(array $sentences, array $phrases): array
    {
        $findings = [];
        foreach ($phrases as $phrase) {
            $needle = mb_strtolower(trim((string) $phrase));
            if ($needle === '') {
                continue;
            }
            $pattern = '/(?<![\p{L}])'.preg_quote($needle, '/').'(?![\p{L}])/u';
            foreach ($sentences as $sentence) {
                if (preg_match($pattern, EvidenceCorpus::normalizeText($sentence))) {
                    $findings[] = $this->finding('banned_phrase', 'high', "Banned phrase \"{$phrase}\" — remove it and say something specific instead.", $sentence);
                }
            }
        }

        return $findings;
    }

    private function dashes(string $text): array
    {
        $count = substr_count($text, '—') + preg_match_all('/\s--\s/', $text) + preg_match_all('/\s–\s/u', $text);
        $words = WordCounter::words($text);

        return $count > self::MAX_DASHES
            ? [$this->finding('dash_overuse', 'medium', "{$count} em dashes in {$words} words (keep at most ".self::MAX_DASHES.' in the whole document); rebuild those sentences with commas, full stops, colons or parentheses rather than swapping the dash.', null)]
            : [];
    }

    private function semicolons(string $text): array
    {
        $count = substr_count($text, ';');
        $allowed = max(2, intdiv(WordCounter::words($text), 250));

        return $count > $allowed
            ? [$this->finding('semicolon_overuse', 'low', "{$count} semicolons (keep it to {$allowed} or fewer); split long sentences or use full stops instead.", null)]
            : [];
    }

    /** @param list<string> $sentences */
    private function ellipses(array $sentences): array
    {
        $findings = [];
        foreach ($sentences as $sentence) {
            if (str_contains($sentence, '...') || str_contains($sentence, '…')) {
                $findings[] = $this->finding('ellipsis', 'low', 'Remove the ellipsis; finish the thought in a complete sentence.', $sentence);
            }
        }

        return $findings;
    }

    /** @param list<string> $sentences */
    private function emptyClaims(array $sentences): array
    {
        $findings = [];
        foreach ($sentences as $sentence) {
            $lower = mb_strtolower(str_replace('’', "'", $sentence));
            foreach (self::EMPTY_CLAIMS as $claim) {
                if (str_contains($lower, $claim)) {
                    $findings[] = $this->finding('empty_claim', 'medium', "Self-description (\"{$claim}\") instead of evidence: show the quality through something specific the applicant did, or remove the sentence.", $sentence);

                    break;
                }
            }
        }

        return $findings;
    }

    /** @param list<string> $sentences */
    private function openings(array $sentences): array
    {
        $openers = array_map(fn (string $s) => SentenceSplitter::words($s)[0] ?? '', $sentences);
        $findings = [];

        $counts = array_count_values(array_filter($openers));
        $total = max(1, count($openers));
        foreach ($counts as $word => $count) {
            if ($count >= 4 && $count / $total > 0.3) {
                $findings[] = $this->finding('repeated_openings', 'medium', "{$count} of {$total} sentences start with \"".ucfirst((string) $word).'"; vary sentence openings.', null);
            }
        }

        for ($i = 2; $i < count($openers); $i++) {
            if ($openers[$i] !== '' && $openers[$i] === $openers[$i - 1] && $openers[$i] === $openers[$i - 2]) {
                $findings[] = $this->finding('consecutive_openings', 'medium', 'Three consecutive sentences start with "'.ucfirst($openers[$i]).'".', $sentences[$i]);
            }
        }

        return $findings;
    }

    /** @param list<string> $sentences */
    private function rhythm(array $sentences, bool $narrative = true): array
    {
        $lengths = array_map(fn (string $s) => count(SentenceSplitter::words($s)), $sentences);
        $findings = [];

        foreach ($sentences as $i => $sentence) {
            if ($lengths[$i] > self::LONG_SENTENCE_WORDS) {
                $findings[] = $this->finding('long_sentence', 'medium', "A {$lengths[$i]}-word sentence; split it into two plain sentences (no sentence above ".self::LONG_SENTENCE_WORDS.' words).', $sentence);
            }
        }

        if ($narrative && count($lengths) >= 6) {
            $mean = array_sum($lengths) / count($lengths);
            $variance = array_sum(array_map(fn ($l) => ($l - $mean) ** 2, $lengths)) / count($lengths);
            $cv = $mean > 0 ? sqrt($variance) / $mean : 0;
            if ($cv < 0.4) {
                $findings[] = $this->finding('monotonous_rhythm', 'medium', sprintf('Sentence lengths are too even (average %.0f words, variation %.2f); mix short sentences with longer ones.', $mean, $cv), null);
            }
            if ($mean > self::AVERAGE_WORDS) {
                $findings[] = $this->finding('long_average', 'medium', sprintf('Sentences average %.0f words; aim for about 15 to 18 by splitting multi-clause sentences.', $mean), null);
            }
            $short = count(array_filter($lengths, fn ($l) => $l >= 3 && $l <= 8));
            if ($short / count($lengths) < 0.15) {
                $findings[] = $this->finding('few_short_sentences', 'medium', sprintf('Only %d of %d sentences are short (3 to 8 words); add a few short, direct sentences.', $short, count($lengths)), null);
            }
        }

        return $findings;
    }

    /** @param list<string> $sentences */
    private function transitions(array $sentences): array
    {
        $used = [];
        foreach ($sentences as $sentence) {
            $start = EvidenceCorpus::normalizeText($sentence);
            foreach (self::STOCK_TRANSITIONS as $transition) {
                if (str_starts_with($start, $transition.',') || str_starts_with($start, $transition.' ')) {
                    $used[] = $sentence;
                }
            }
        }

        return count($used) >= 2
            ? [$this->finding('stock_transitions', 'medium', count($used).' sentences start with stock transitions (Furthermore, Moreover, Additionally...); let the ideas connect instead.', $used[0])]
            : [];
    }

    /** Whether these findings justify an editorial model call: two or more it can fix, or any high one. */
    public static function needsEditorialPass(array $findings): bool
    {
        $relevant = array_filter($findings, fn (array $f) => in_array($f['type'], self::EDITABLE_TYPES, true) && in_array($f['severity'], ['medium', 'high'], true));

        return count($relevant) >= 2 || array_filter($relevant, fn (array $f) => $f['severity'] === 'high') !== [];
    }

    /**
     * Heavy wording: long average word length, too few everyday function words, and stiff
     * words or labels. Names that must stay as written (institution, programme) are ignored.
     *
     * @param  list<string>  $sentences
     * @param  list<string>  $protectedTerms
     */
    private function plainness(array $sentences, array $protectedTerms): array
    {
        $text = implode(' ', $sentences);
        foreach ($protectedTerms as $term) {
            if ($term !== '') {
                // Whole words only: a programme called "MA" must not turn "human" into "hu n".
                $text = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu', ' ', $text) ?? $text;
            }
        }

        $words = SentenceSplitter::words($text);
        if (count($words) < 60) {
            return [];
        }

        $findings = [];
        $lower = array_map(fn (string $w) => mb_strtolower($w), $words);

        $averageLength = array_sum(array_map('mb_strlen', $words)) / count($words);
        if ($averageLength > self::AVERAGE_WORD_LENGTH) {
            $long = array_slice(array_values(array_unique(array_filter($lower, fn ($w) => mb_strlen($w) >= 12))), 0, 8);
            $findings[] = $this->finding('long_words', 'medium', sprintf('Words average %.1f letters; use plainer, shorter words%s.', $averageLength, $long === [] ? '' : ' (for example instead of: '.implode(', ', $long).')'), null);
        }

        $share = count(array_filter($lower, fn ($w) => in_array($w, self::FUNCTION_WORDS, true))) / count($lower);
        if ($share < self::MIN_FUNCTION_WORD_SHARE) {
            $findings[] = $this->finding('dense_wording', 'low', sprintf('The wording is dense (%.0f%% everyday words like I, the, to, it, that); write it the way you would say it.', $share * 100), null);
        }

        $haystack = ' '.mb_strtolower(str_replace('’', "'", $text)).' ';
        $stiff = array_values(array_filter(self::STIFF_WORDS, fn ($w) => preg_match('/\b'.preg_quote($w, '/').'\b/u', $haystack) === 1));
        if ($stiff !== []) {
            $findings[] = $this->finding('stiff_wording', 'medium', 'Stiff, machine-like wording: '.implode(', ', $stiff).'. Say the same thing in plain words.', null);
        }

        return $findings;
    }

    /** @param list<string> $sentences */
    private function exclamations(array $sentences): array
    {
        $findings = [];
        foreach ($sentences as $sentence) {
            if (str_contains($sentence, '!')) {
                $findings[] = $this->finding('exclamation', 'low', 'Avoid exclamation marks in an application document.', $sentence);
            }
        }

        return $findings;
    }

    private function spelling(string $text, string $variant, array $protectedTerms): array
    {
        $preferences = LanguageGuide::preferences($variant);
        $lower = mb_strtolower($text);
        foreach ($protectedTerms as $term) {
            if (is_string($term) && trim($term) !== '') {
                $lower = str_replace(mb_strtolower($term), ' ', $lower);
            }
        }

        $wrong = [];
        foreach (self::SPELLINGS as $group => $pairs) {
            foreach ($pairs as $british => $american) {
                $unwanted = $preferences[$group] ? $american : $british;
                $wanted = $preferences[$group] ? $british : $american;
                // "program" stays correct for software even in British English.
                if ($group === 'programme' && $preferences[$group]) {
                    continue;
                }
                if (preg_match('/\b'.preg_quote($unwanted, '/').'\b/u', $lower)) {
                    $wrong[] = "\"{$unwanted}\" → \"{$wanted}\"";
                }
            }
        }

        return $wrong === []
            ? []
            : [$this->finding('variant_spelling', 'medium', 'Spelling does not match '.$variant.': '.implode(', ', array_slice($wrong, 0, 12)).'.', null)];
    }

    private function finding(string $type, string $severity, string $message, ?string $excerpt): array
    {
        return ['type' => $type, 'severity' => $severity, 'message' => $message, 'excerpt' => $excerpt];
    }
}
