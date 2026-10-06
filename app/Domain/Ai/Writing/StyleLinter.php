<?php

namespace App\Domain\Ai\Writing;

use App\Domain\Ai\Prompts\LanguageGuide;
use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\WordCounter;

/**
 * Deterministic style checks whose findings feed the editorial pass:
 * banned phrases (Settings ai.banned_phrases), em-dash overuse, repeated
 * sentence openings, monotonous sentence length, overlong sentences, stock
 * transitions, exclamation marks and spelling that does not match the
 * required English variant. The goal is authentic, specific writing — not
 * gaming AI detectors.
 */
final class StyleLinter
{
    private const STOCK_TRANSITIONS = ['furthermore', 'moreover', 'additionally', 'in conclusion', 'overall', 'ultimately', 'in summary', 'to conclude', 'notably', 'importantly'];

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
    public function lint(DocumentModel $document, array $bannedPhrases, string $languageVariant = 'en-GB', array $protectedTerms = []): array
    {
        $paragraphs = $document->paragraphs();
        $text = implode("\n\n", $paragraphs);
        $sentences = [];
        foreach ($paragraphs as $paragraph) {
            $sentences = [...$sentences, ...SentenceSplitter::split($paragraph)];
        }

        return [
            ...$this->bannedPhrases($sentences, $bannedPhrases),
            ...$this->dashes($text),
            ...$this->openings($sentences),
            ...$this->rhythm($sentences),
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
        $allowed = max(2, intdiv($words, 300));

        return $count > $allowed
            ? [$this->finding('dash_overuse', 'medium', "{$count} em dashes in {$words} words (keep it to {$allowed} or fewer); use commas, full stops or parentheses instead.", null)]
            : [];
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
    private function rhythm(array $sentences): array
    {
        $lengths = array_map(fn (string $s) => count(SentenceSplitter::words($s)), $sentences);
        $findings = [];

        foreach ($sentences as $i => $sentence) {
            if ($lengths[$i] > 45) {
                $findings[] = $this->finding('long_sentence', 'low', "A {$lengths[$i]}-word sentence; split or tighten it.", $sentence);
            }
        }

        if (count($lengths) >= 6) {
            $mean = array_sum($lengths) / count($lengths);
            $variance = array_sum(array_map(fn ($l) => ($l - $mean) ** 2, $lengths)) / count($lengths);
            $cv = $mean > 0 ? sqrt($variance) / $mean : 0;
            if ($cv < 0.3) {
                $findings[] = $this->finding('monotonous_rhythm', 'medium', sprintf('Sentence lengths are very uniform (average %.0f words, variation %.2f); mix short and longer sentences.', $mean, $cv), null);
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
