<?php

namespace App\Domain\Ai\Llm;

use App\Domain\Ai\Writing\SentenceSplitter;
use App\Domain\Documents\WordCounter;
use App\Models\QualityReview;
use Illuminate\Support\Str;

/**
 * Synthesises deterministic, schema-valid output for the fake provider from
 * the request context the stages attach (answers, profile facts, claims,
 * drafts...). It never calls anything external: research "sources" live on
 * reserved *.example domains so verification never fetches them.
 */
class FakeOutputs
{
    public function generate(LlmRequest $request): array
    {
        $c = $request->context;

        return match ($request->task ?: $request->promptKey) {
            'ingestion' => $this->ingestion($c),
            'analysis' => $this->analysis($c),
            'research' => $this->research($c),
            'verification' => $this->verification($c),
            'strategy' => $this->strategy($c),
            'writing' => $this->writing($c),
            'editorial', 'refinement' => $this->polish($c),
            'fact_check' => $this->factCheck($c),
            'fact_fix' => $this->factFix($c),
            'quality_review' => $this->qualityReview($c),
            'limits' => $this->lengthFit($c),
            'revision' => $this->revision($c),
            default => throw new \InvalidArgumentException("The fake provider cannot answer task [{$request->task}]."),
        };
    }

    public static function fakeDomain(?string $institution): string
    {
        $slug = Str::slug((string) ($institution ?: 'institution'));

        return ($slug !== '' ? $slug : 'institution').'.example';
    }

    // -------------------------------------------------------------- ingestion

    private function ingestion(array $c): array
    {
        $facts = [];
        $add = function (string $category, string $statement, string $type, string $ref, string $quote) use (&$facts) {
            preg_match('/\b(19|20)\d{2}\b/', $statement, $year);
            $facts[] = [
                'id' => 'F'.(count($facts) + 1),
                'category' => $category,
                'statement' => $statement,
                'date' => $year[0] ?? null,
                'source_type' => $type,
                'source_ref' => $ref,
                'evidence_quote' => $quote,
                'confidence' => 'high',
            ];
        };

        $guidance = [];
        foreach ((array) ($c['answers'] ?? []) as $answer) {
            $key = (string) $answer['key'];
            if ($key === 'additional_notes') {
                // A note about the document is an instruction (it may also contain facts, recorded below).
                $quote = Str::words((string) $answer['answer'], 30, '');
                $guidance[] = ['type' => 'instruction', 'source_type' => 'answer', 'source_ref' => $key, 'quote' => $quote, 'guidance' => 'Follow the customer\'s note: '.$quote];
            }
            if ($answer['section'] === 'details' || ($answer['section'] === 'application' && ! str_starts_with($key, 'followup_'))) {
                continue;
            }
            foreach (array_slice(SentenceSplitter::split((string) $answer['answer']), 0, 5) as $sentence) {
                $add($this->categoryFor($key), $sentence, 'answer', $key, Str::words($sentence, 30, ''));
            }
        }

        foreach ((array) ($c['documents'] ?? []) as $document) {
            $lines = array_filter(array_map('trim', preg_split('/\n+/', (string) ($document['text'] ?? '')) ?: []), fn ($l) => str_word_count($l) >= 4);
            foreach (array_slice(array_values($lines), 0, 4) as $line) {
                $category = match ($document['purpose'] ?? null) {
                    'cv' => 'work_history',
                    'transcript' => 'academic_history',
                    default => 'other',
                };
                $add($category, Str::limit($line, 240, ''), 'file', (string) $document['file_id'], Str::words($line, 30, ''));
            }
        }

        $name = $c['applicant_name'] ?? null;
        $order = (array) ($c['order'] ?? []);
        $hasStory = count(array_filter($facts, fn ($f) => in_array($f['category'], ['motivation', 'academic_history', 'work_history', 'project', 'achievement', 'key_experience'], true))) > 0;

        return [
            'full_name' => $name,
            'summary' => trim(($name ?: 'The applicant').' is applying for '.($order['programme'] ?? 'a programme').' at '.($order['institution'] ?? 'the chosen institution').'.'),
            'facts' => $facts,
            'inconsistencies' => [],
            'gaps' => $hasStory ? [] : [['item' => 'Academic or professional background and motivation', 'critical' => true, 'why' => 'Nothing describes the applicant\'s experience.']],
            'customer_guidance' => $guidance,
        ];
    }

    private function categoryFor(string $key): string
    {
        return match (true) {
            str_contains($key, 'goal'), str_contains($key, 'career'), str_contains($key, 'impact'), str_contains($key, 'future') => 'career_goal',
            str_contains($key, 'achievement'), str_contains($key, 'award') => 'achievement',
            str_contains($key, 'research_interest'), str_contains($key, 'interest') => 'interest',
            str_contains($key, 'project') => 'project',
            str_contains($key, 'skill') => 'skill',
            str_contains($key, 'experience'), str_contains($key, 'work') => 'work_history',
            str_contains($key, 'background'), str_contains($key, 'academic'), str_contains($key, 'education') => 'academic_history',
            str_contains($key, 'why'), str_contains($key, 'motivation') => 'motivation',
            str_contains($key, 'circumstance') => 'personal_background',
            default => 'key_experience',
        };
    }

    // --------------------------------------------------------------- analysis

    private function analysis(array $c): array
    {
        $order = (array) ($c['order'] ?? []);
        $facts = (array) ($c['facts'] ?? []);
        $institution = (string) ($order['institution'] ?? 'the institution');
        $programme = (string) ($order['programme'] ?? 'the programme');
        $question = (string) ($order['essay_question'] ?? '');

        $story = array_values(array_filter($facts, fn ($f) => in_array($f['category'] ?? '', ['motivation', 'academic_history', 'work_history', 'project', 'achievement', 'key_experience', 'interest'], true)));
        $missing = [];
        if (count($story) < 2) {
            $missing[] = [
                'question' => 'Could you briefly describe your academic or work background and what first drew you to this field?',
                'why' => 'There is not enough about your experience to write an accurate, specific document.',
                'critical' => true,
                'suggested_answers' => ['I studied … at …, and what first drew me to this field was …', 'In my work as … I …, which made me want to …'],
            ];
        }
        if (! array_filter($facts, fn ($f) => ($f['category'] ?? '') === 'career_goal')) {
            $missing[] = ['question' => 'What would you like to do after completing this programme?', 'why' => 'Goals help show programme fit.', 'critical' => false, 'suggested_answers' => ['After the programme, I want to …']];
        }

        preg_match('/(\d[\d,]*)\s*words/i', $question, $words);
        preg_match('/(\d[\d,]*)\s*characters/i', $question, $chars);
        $variant = match (strtoupper((string) ($order['country'] ?? ''))) {
            'US' => 'en-US',
            'CA' => 'en-CA',
            'AU' => 'en-AU',
            'NZ' => 'en-NZ',
            'IE' => 'en-IE',
            default => 'en-GB',
        };

        return [
            'prompt_interpretation' => $question !== ''
                ? "Answer the question \"{$question}\" with specific evidence from the applicant's own experience and a clear link to {$programme}."
                : "Explain the applicant's motivation, preparation and fit for {$programme} at {$institution}.",
            'essay_questions' => $question !== '' ? [['question' => $question, 'heading' => null]] : [],
            'qualities_to_demonstrate' => ['Genuine, specific motivation for the subject', 'Relevant preparation and evidence', 'Clear fit with the programme', 'Realistic goals'],
            'experiences_to_emphasise' => array_map(fn ($f) => ['fact_id' => $f['id'], 'why' => 'Concrete evidence from the applicant\'s own material.'], array_slice($story, 0, 6)),
            'research_questions' => [
                ['question' => "Which modules, research areas or opportunities in {$programme} at {$institution} connect to the applicant's interests?", 'purpose' => 'programme_fit'],
                ['question' => "What length, format and submission requirements apply to this document for {$programme} at {$institution}?", 'purpose' => 'requirements'],
            ],
            'claims_requiring_verification' => [],
            'official_domains' => [['domain' => self::fakeDomain($institution), 'entity' => $institution, 'confidence' => 0.9]],
            'application_platform' => null,
            'stated_limits' => [
                'max_words' => isset($words[1]) ? (int) str_replace(',', '', $words[1]) : null,
                'min_words' => null,
                'max_characters' => isset($chars[1]) ? (int) str_replace(',', '', $chars[1]) : null,
                'min_characters' => null,
                'max_pages' => null,
                'source' => isset($words[1]) || isset($chars[1]) ? 'essay_question' : null,
                'quote' => isset($words[0]) ? $words[0] : ($chars[0] ?? null),
            ],
            'required_sections' => [],
            'language_variant' => $variant,
            'language_variant_source' => 'inferred_from_country',
            'missing_information' => $missing,
            'risks' => [],
        ];
    }

    // --------------------------------------------------------------- research

    private function research(array $c): array
    {
        $order = (array) ($c['order'] ?? []);
        if (($c['pass'] ?? 'official') !== 'official') {
            return ['programme_found' => true, 'claims' => [], 'fit_summary' => 'No additional secondary information was needed.', 'requirements_summary' => '', 'gaps' => []];
        }

        $institution = (string) ($order['institution'] ?? 'the institution');
        $programme = (string) ($order['programme'] ?? 'the programme');
        $domain = (string) (($c['allowed_domains'][0] ?? null) ?: self::fakeDomain($institution));
        $base = 'https://www.'.$domain;
        $page = $base.'/study/'.Str::slug($programme);
        $interest = (string) ($c['interests'][0] ?? 'the applicant\'s interests');

        $claim = fn (string $text, string $category, string $url, string $quote, float $confidence = 0.92, ?string $field = null, ?string $value = null) => [
            'claim' => $text,
            'category' => $category,
            'source_url' => $url,
            'source_title' => "{$programme} | {$institution}",
            'source_type' => 'official_programme',
            'supporting_quote' => $quote,
            'confidence' => $confidence,
            'relevance' => "Connects to {$interest}.",
            'relevance_score' => 0.8,
            'requirement_field' => $field,
            'requirement_value' => $value,
        ];

        $claims = [
            $claim("The {$programme} combines core modules with a range of optional modules.", 'structure', $page, "The {$programme} combines core modules with a range of optional modules."),
            $claim("Students on the {$programme} complete an independent research project.", 'structure', $page, 'Students complete an independent research project.'),
            $claim("The {$programme} prepares graduates for careers in industry and research.", 'career_outcome', $page.'/careers', 'Our graduates go on to careers in industry and research.'),
        ];

        if (! empty($order['word_limit_stated_by_customer'])) {
            $limit = (string) $order['word_limit_stated_by_customer'];
            $claims[] = $claim("The personal statement must not exceed {$limit} words.", 'document_requirement', $base.'/admissions/apply', "Your personal statement must not exceed {$limit} words.", 0.95, 'max_words', $limit);
        }

        return [
            'programme_found' => true,
            'claims' => $claims,
            'fit_summary' => "{$programme} at {$institution} offers a structured curriculum with an independent project.",
            'requirements_summary' => ! empty($order['word_limit_stated_by_customer']) ? 'A word limit is stated on the admissions page.' : 'No document-specific limits were found.',
            'gaps' => [],
        ];
    }

    private function verification(array $c): array
    {
        return [
            'reviews' => array_map(fn ($claim) => ['claim_id' => (string) $claim['claim_id'], 'supported' => true, 'notes' => 'The quote states the claim directly.'], (array) ($c['claims'] ?? [])),
            'conflicts' => [],
        ];
    }

    // --------------------------------------------------------------- strategy

    private function strategy(array $c): array
    {
        $facts = (array) ($c['facts'] ?? []);
        $claims = array_column((array) ($c['claims'] ?? []), 'claim_id');
        $ids = fn (array $categories, int $limit = 3) => array_slice(array_column(array_filter($facts, fn ($f) => in_array($f['category'] ?? '', $categories, true)), 'id'), 0, $limit);
        $target = max(150, (int) ($c['target_words'] ?? 650));

        $plan = [
            ['purpose' => 'Opening: the moment the interest took shape', 'section_heading' => null, 'target_words' => (int) round($target * 0.18), 'fact_ids' => $ids(['motivation', 'interest'], 2), 'claim_ids' => []],
            ['purpose' => 'Academic and practical preparation', 'section_heading' => null, 'target_words' => (int) round($target * 0.27), 'fact_ids' => $ids(['academic_history', 'work_history', 'project']), 'claim_ids' => []],
            ['purpose' => 'Achievements and what they taught', 'section_heading' => null, 'target_words' => (int) round($target * 0.18), 'fact_ids' => $ids(['achievement', 'skill', 'key_experience']), 'claim_ids' => []],
            ['purpose' => 'Why this programme', 'section_heading' => null, 'target_words' => (int) round($target * 0.22), 'fact_ids' => $ids(['motivation', 'interest'], 1), 'claim_ids' => array_slice($claims, 0, 3)],
            ['purpose' => 'Goals and close', 'section_heading' => null, 'target_words' => (int) round($target * 0.15), 'fact_ids' => $ids(['career_goal']), 'claim_ids' => []],
        ];

        return [
            'central_thread' => 'Real experience leading naturally to this programme and to concrete goals.',
            'opening' => ['approach' => 'Begin with the applicant\'s own account of how the interest started.', 'fact_ids' => $ids(['motivation', 'interest'], 2)],
            'evidence' => array_map(fn ($id) => ['point' => 'Show what the applicant did and learned.', 'fact_ids' => [$id]], $ids(['academic_history', 'work_history', 'project', 'achievement'])),
            'development' => 'Show how each experience deepened the interest.',
            'programme_fit' => $claims ? [['point' => 'Link the programme structure to the applicant\'s interests.', 'claim_ids' => array_slice($claims, 0, 3), 'fact_ids' => $ids(['motivation', 'interest'], 1)]] : [],
            'future_goals' => ['point' => 'State the applicant\'s goals as they described them.', 'fact_ids' => $ids(['career_goal'])],
            'contribution' => ['point' => 'What the applicant brings to the cohort.', 'fact_ids' => $ids(['skill', 'achievement'], 2)],
            'conclusion' => 'Close by looking ahead to the programme.',
            'paragraph_plan' => $plan,
            'tone' => 'Reflective, precise and confident without overstatement.',
            'avoid' => ['Generic statements about the field', 'Repeating the CV line by line'],
            'proposals_to_confirm' => $ids(['career_goal']) === [] ? ['We proposed your future goals from your experience, because you did not state them.'] : [],
        ];
    }

    // ---------------------------------------------------------------- writing

    private function writing(array $c): array
    {
        $facts = (array) ($c['facts'] ?? []);
        $claims = (array) ($c['claims'] ?? []);
        $order = (array) ($c['order'] ?? []);
        $us = in_array($c['language_variant'] ?? 'en-GB', ['en-US', 'en-CA', 'en-AU'], true);
        $programmeWord = $us ? 'program' : 'programme';
        $programme = (string) ($order['programme'] ?? "this {$programmeWord}");
        $institution = (string) ($order['institution'] ?? 'your institution');
        $used = [];

        $sentences = function (array $categories, int $limit) use ($facts, &$used): array {
            $out = [];
            foreach ($facts as $fact) {
                if (count($out) >= $limit) {
                    break;
                }
                if (in_array($fact['category'] ?? '', $categories, true) && ($fact['source_type'] ?? 'answer') === 'answer') {
                    $out[] = rtrim((string) $fact['statement'], '.').'.';
                    $used[] = (string) $fact['id'];
                }
            }

            return $out;
        };

        $opening = $sentences(['motivation', 'interest'], 3) ?: ['My interest in this field has grown steadily through my studies and work.'];
        $preparation = $sentences(['academic_history', 'work_history', 'project'], 4);
        $evidence = $sentences(['achievement', 'skill', 'key_experience', 'personal_background', 'other'], 3);
        $goals = $sentences(['career_goal'], 2);

        $at = $this->withArticle($institution);
        $fit = ["I am applying to the {$programme} at {$at} because it builds directly on this experience."];
        $openers = ['What draws me to the '.$programmeWord.' is that ', 'I am also encouraged that ', 'It matters to me that '];
        $usedClaims = [];
        foreach (array_slice(array_values($claims), 0, 3) as $i => $claim) {
            $text = rtrim((string) $claim['claim'], '.');
            $fit[] = $openers[$i % count($openers)].Str::lcfirst($text).'.';
            $usedClaims[] = (string) $claim['claim_id'];
        }

        $paragraphs = array_values(array_filter([
            implode(' ', $opening),
            implode(' ', $preparation),
            implode(' ', $evidence),
            implode(' ', $fit),
            implode(' ', [...$goals, "I hope to bring the same commitment to the {$programme} at {$at}."]),
        ], fn ($p) => trim($p) !== ''));

        $paragraphs = $this->trimToWords($paragraphs, (int) ($c['max_words'] ?? 0), (int) ($c['max_characters'] ?? 0));

        $blocks = [];
        $sections = (array) ($c['sections'] ?? []);
        foreach ($paragraphs as $i => $paragraph) {
            if (isset($sections[$i]['heading'])) {
                $blocks[] = ['type' => 'heading', 'text' => (string) $sections[$i]['heading']];
            }
            $blocks[] = ['type' => 'paragraph', 'text' => $paragraph];
        }

        if (! empty($c['letter'])) {
            array_unshift($blocks, ['type' => 'salutation', 'text' => 'Dear Admissions Committee,']);
            $blocks[] = ['type' => 'closing', 'text' => ($c['language_variant'] ?? 'en-GB') === 'en-US' ? 'Sincerely,' : 'Yours sincerely,'];
            $blocks[] = ['type' => 'signature', 'text' => (string) ($c['applicant_name'] ?? 'Applicant')];
        }

        return [
            'title' => null,
            'blocks' => $blocks,
            'used_fact_ids' => array_values(array_unique($used)),
            'used_claim_ids' => $usedClaims,
            'notes' => 'Draft assembled from the applicant\'s own statements and the verified dossier.',
        ];
    }

    /** Editorial / refinement: remove banned phrases and dash chains, keep everything else. */
    private function polish(array $c): array
    {
        $banned = array_map('strval', (array) ($c['banned_phrases'] ?? []));
        $blocks = [];
        foreach ((array) ($c['document']['blocks'] ?? []) as $block) {
            $text = (string) $block['text'];
            foreach ($banned as $phrase) {
                if ($phrase !== '') {
                    $text = (string) preg_replace('/\s*\b'.preg_quote($phrase, '/').'\b/iu', '', $text);
                }
            }
            $text = str_replace([' — ', '—'], [', ', ', '], $text);
            $blocks[] = ['type' => (string) $block['type'], 'text' => trim(preg_replace('/\s{2,}/', ' ', $text) ?? $text)];
        }

        return $this->draftOutput($c, $blocks, 'Polished rhythm and removed flagged phrases.');
    }

    private function factCheck(array $c): array
    {
        $map = [
            'unsupported_number' => 'wrong_date_or_number',
            'url_or_citation' => 'citation_or_url',
            'unknown_institution' => 'wrong_institution_or_programme',
            'unknown_programme' => 'wrong_institution_or_programme',
        ];

        $issues = array_map(fn ($finding) => [
            'excerpt' => (string) $finding['excerpt'],
            'problem' => (string) $finding['problem'],
            'type' => $map[$finding['type']] ?? 'other',
            'severity' => 'high',
            'fix' => 'Remove the unsupported detail.',
        ], (array) ($c['findings'] ?? []));

        return [
            'verdict' => $issues === [] ? 'pass' : 'fix_required',
            'issues' => $issues,
            'used_claim_ids' => array_values(array_map('strval', (array) ($c['used_claim_ids'] ?? []))),
        ];
    }

    private function factFix(array $c): array
    {
        $excerpts = array_filter(array_map(fn ($i) => trim((string) ($i['excerpt'] ?? '')), (array) ($c['issues'] ?? [])));
        $blocks = [];
        foreach ((array) ($c['document']['blocks'] ?? []) as $block) {
            if ($block['type'] !== 'paragraph') {
                $blocks[] = ['type' => (string) $block['type'], 'text' => (string) $block['text']];

                continue;
            }
            $kept = array_filter(SentenceSplitter::split((string) $block['text']), function (string $sentence) use ($excerpts) {
                foreach ($excerpts as $excerpt) {
                    if (str_contains($sentence, $excerpt) || str_contains($excerpt, $sentence)) {
                        return false;
                    }
                }

                return true;
            });
            if ($kept !== []) {
                $blocks[] = ['type' => 'paragraph', 'text' => implode(' ', $kept)];
            }
        }

        return $this->draftOutput($c, $blocks, 'Removed statements that were not supported by the material.');
    }

    private function qualityReview(array $c): array
    {
        $seed = crc32(json_encode($c['document'] ?? []) ?: '');
        $scores = [];
        foreach (array_keys(QualityReview::CATEGORIES) as $i => $category) {
            $scores[$category] = 8.4 + ((($seed >> $i) & 7) / 10); // 8.4 – 9.1
        }

        return [
            'scores' => $scores,
            'answers_prompt' => true,
            'prompt_adherence_notes' => 'The document answers the question directly with the applicant\'s own evidence.',
            'issues' => [],
            'instructions' => 'No changes required.',
            'strengths' => ['Specific, first-hand evidence', 'Clear link to the programme'],
        ];
    }

    private function lengthFit(array $c): array
    {
        $blocks = (array) ($c['document']['blocks'] ?? []);
        $paragraphs = array_column(array_filter($blocks, fn ($b) => $b['type'] === 'paragraph'), 'text');
        $trimmed = $this->trimToWords($paragraphs, (int) ($c['targets']['words'] ?? 0), (int) ($c['targets']['characters'] ?? 0));

        $out = [];
        $index = 0;
        foreach ($blocks as $block) {
            if ($block['type'] !== 'paragraph') {
                $out[] = ['type' => (string) $block['type'], 'text' => (string) $block['text']];

                continue;
            }
            if (isset($trimmed[$index])) {
                $out[] = ['type' => 'paragraph', 'text' => $trimmed[$index]];
            }
            $index++;
        }

        return $this->draftOutput($c, $out, 'Adjusted the length to the target.');
    }

    private function revision(array $c): array
    {
        $blocks = array_map(fn ($b) => ['type' => (string) $b['type'], 'text' => (string) $b['text']], (array) ($c['document']['blocks'] ?? []));

        return $this->draftOutput($c, $blocks, 'Applied the requested changes where the material supports them.');
    }

    // ---------------------------------------------------------------- helpers

    /** "University of Oxford" reads as "the University of Oxford" mid-sentence. */
    private function withArticle(string $name): string
    {
        return preg_match('/^(University|Institute|College|School|Academy) of\b/i', $name) ? 'the '.$name : $name;
    }

    private function draftOutput(array $c, array $blocks, string $notes): array
    {
        return [
            'title' => isset($c['document']['title']) ? (string) $c['document']['title'] : null,
            'blocks' => array_values($blocks),
            'used_fact_ids' => array_values(array_map('strval', (array) ($c['used_fact_ids'] ?? []))),
            'used_claim_ids' => array_values(array_map('strval', (array) ($c['used_claim_ids'] ?? []))),
            'notes' => $notes,
        ];
    }

    /**
     * Drop trailing sentences of the longest paragraphs until the text fits
     * the word / character targets (0 = no target).
     *
     * @param  list<string>  $paragraphs
     * @return list<string>
     */
    private function trimToWords(array $paragraphs, int $maxWords, int $maxCharacters): array
    {
        $fits = function (array $ps) use ($maxWords, $maxCharacters) {
            $text = implode("\n\n", $ps);

            return ($maxWords <= 0 || WordCounter::words($text) <= $maxWords)
                && ($maxCharacters <= 0 || WordCounter::characters($text) <= $maxCharacters);
        };

        $paragraphs = array_values($paragraphs);
        $guard = 0;
        while (! $fits($paragraphs) && $guard++ < 200) {
            $longest = 0;
            foreach ($paragraphs as $i => $p) {
                if (WordCounter::words($p) > WordCounter::words($paragraphs[$longest])) {
                    $longest = $i;
                }
            }
            $sentences = SentenceSplitter::split($paragraphs[$longest]);
            if (count($sentences) <= 1) {
                $words = preg_split('/\s+/', $paragraphs[$longest]) ?: [];
                $paragraphs[$longest] = implode(' ', array_slice($words, 0, max(1, (int) floor(count($words) * 0.8))));
            } else {
                array_pop($sentences);
                $paragraphs[$longest] = implode(' ', $sentences);
            }
            $paragraphs = array_values(array_filter($paragraphs, fn ($p) => trim($p) !== ''));
            if ($paragraphs === []) {
                break;
            }
        }

        return $paragraphs;
    }
}
