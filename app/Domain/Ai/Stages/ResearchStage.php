<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Llm\LlmCall;
use App\Domain\Ai\Llm\LlmGateway;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Ai\Prompts\PromptValue;
use App\Domain\Ai\Research\UrlNormalizer;

/**
 * Web research with OpenAI web search, official sources first.
 *
 * Pass 1 searches only the official domain(s) found by the analysis (when
 * known). Pass 2 searches more broadly, only if the workflow allows secondary
 * sources and pass 1 left gaps (no official domain, programme not found, too
 * little programme-fit material or no requirement information). Claims carry
 * URL, title, source type, verbatim quote, confidence and relevance; the URLs
 * the model actually saw (search sources + url_citation annotations) are
 * recorded for verification.
 *
 * A CV is tailored from the job description the customer pasted, so for a
 * resume order the web search runs only when the order names a target
 * employer or organisation or gives a job-posting link. Otherwise the stage
 * completes without a model call (and verification has nothing to check).
 */
class ResearchStage implements Stage
{
    private const MIN_FIT_CLAIMS = 2;

    /** Answer keys or labels that name a target employer or organisation. */
    private const EMPLOYER_FIELD = '/\b(employer|company|organi[sz]ation|firm)\b/i';

    /** A link, with or without a scheme (job postings are often pasted as "www..."). */
    private const LINK = '~\bhttps?://[^\s<>"]+|\bwww\.[a-z0-9-]+(\.[a-z0-9-]+)+~i';

    private const MAX_CLAIMS_PER_PASS = 25;

    public function __construct(private readonly LlmGateway $llm) {}

    public function run(StageContext $ctx): StageResult
    {
        if (($skipped = $this->skipReason($ctx)) !== null) {
            return StageResult::completed([
                'skipped' => true,
                'skip_reason' => $skipped,
                'claims' => [],
                'seen_urls' => [],
                'official_domains' => [],
                'programme_found' => false,
                'fit_summary' => '',
                'requirements_summary' => '',
                'gaps' => [],
                'passes' => [],
            ]);
        }

        $analysis = $ctx->analysis();
        $officialDomains = array_values(array_unique(array_map(
            fn ($d) => (string) $d['domain'],
            array_filter((array) ($analysis['official_domains'] ?? []), fn ($d) => (float) ($d['confidence'] ?? 0) >= 0.5),
        )));

        $searchAllowance = max(1, (int) $ctx->config('research.max_search_calls', 12));
        $allowSecondary = (bool) $ctx->config('research.allow_secondary_sources', true);
        $officialFirst = (bool) $ctx->config('research.official_first', true);

        $brief = [
            'essay_questions' => $analysis['essay_questions'] ?? [],
            'research_questions' => $analysis['research_questions'] ?? [],
            'qualities_to_demonstrate' => $analysis['qualities_to_demonstrate'] ?? [],
            'claims_requiring_verification' => $analysis['claims_requiring_verification'] ?? [],
            'application_platform' => $analysis['application_platform'] ?? null,
        ];
        $interests = $this->interests($ctx);

        $firstBudget = $allowSecondary ? max(1, (int) ceil($searchAllowance * 0.6)) : $searchAllowance;
        $restrictToOfficial = $officialFirst && $officialDomains !== [];

        $first = $this->pass($ctx, 'official', $restrictToOfficial ? $officialDomains : [], $firstBudget, $brief, $officialDomains, $interests, $restrictToOfficial
            ? 'Official-sources pass: the search tool is restricted to the likely official domains. Cover the programme page, admissions or how-to-apply pages, department and faculty pages, and any requirements for this document.'
            : 'Official-sources pass: no official domain is known yet. First identify the official website of the institution and programme, then use official pages only.');

        $passes = [$first];
        $remaining = $searchAllowance - $first['search_calls'];

        if ($allowSecondary && $remaining > 0 && $this->needsBroaderPass($first, $officialDomains)) {
            $brief['gaps_after_official_pass'] = $first['gaps'];
            $passes[] = $this->pass($ctx, 'broad', [], $remaining, $brief, $officialDomains, $interests,
                'Broader pass: find what the official pass could not (see gaps_after_official_pass in the brief). Prefer official pages on any domain (the institution, the official application platform, government or education authorities); use secondary sources only when nothing official exists and label them "secondary".');
        }

        // Number claims C1..Cn across passes; drop duplicates and malformed URLs.
        $claims = [];
        $seenKeys = [];
        foreach ($passes as $pass) {
            foreach ($pass['claims'] as $claim) {
                $url = UrlNormalizer::normalize((string) $claim['source_url']);
                if ($url === null) {
                    continue;
                }
                $dedupe = $url.'|'.mb_strtolower(trim((string) $claim['claim']));
                if (isset($seenKeys[$dedupe])) {
                    continue;
                }
                $seenKeys[$dedupe] = true;
                $claims[] = ['id' => 'C'.(count($claims) + 1), 'pass' => $pass['pass']] + $claim + ['source_url_normalized' => $url];
            }
        }

        return StageResult::completed([
            'claims' => $claims,
            'seen_urls' => array_values(array_unique(array_merge(...array_map(fn ($p) => $p['seen_urls'], $passes)))),
            'official_domains' => $officialDomains,
            'programme_found' => (bool) array_filter(array_column($passes, 'programme_found')),
            'fit_summary' => implode(' ', array_filter(array_column($passes, 'fit_summary'))),
            'requirements_summary' => implode(' ', array_filter(array_column($passes, 'requirements_summary'))),
            'gaps' => array_values(array_unique(array_merge(...array_map(fn ($p) => $p['gaps'], $passes)))),
            'passes' => array_map(fn ($p) => ['pass' => $p['pass'], 'claims' => count($p['claims']), 'search_calls' => $p['search_calls'], 'queries' => count($p['queries'])], $passes),
        ]);
    }

    private function pass(StageContext $ctx, string $pass, array $allowedDomains, int $maxCalls, array $brief, array $officialDomains, array $interests, string $scope): array
    {
        $result = $this->llm->call($ctx, new LlmCall(
            task: 'research',
            variables: [
                'search_scope' => PromptValue::trusted($scope),
                'document_type' => PromptValue::trusted($ctx->documentTypeLabel()),
                'order_details' => $ctx->orderDetails(),
                'research_brief' => $brief,
                'official_domains' => $officialDomains,
                'applicant_interests' => $interests,
                'max_claims' => self::MAX_CLAIMS_PER_PASS,
            ],
            webSearch: [
                'allowed_domains' => $allowedDomains,
                'search_context_size' => (string) $ctx->config('research.search_context_size', 'medium'),
                'country' => $ctx->order->country_code,
                'max_tool_calls' => $maxCalls,
            ],
            context: [
                'order' => $ctx->orderDetails(),
                'pass' => $pass,
                'allowed_domains' => $allowedDomains ?: $officialDomains,
                'interests' => $interests,
            ],
            label: $pass,
        ));

        return [
            'pass' => $pass,
            'claims' => array_slice((array) $result->data['claims'], 0, self::MAX_CLAIMS_PER_PASS),
            'programme_found' => (bool) $result->data['programme_found'],
            'fit_summary' => (string) $result->data['fit_summary'],
            'requirements_summary' => (string) $result->data['requirements_summary'],
            'gaps' => array_values(array_map('strval', (array) $result->data['gaps'])),
            'seen_urls' => $result->response->seenUrls(),
            'queries' => $result->response->searchQueries,
            'search_calls' => $result->response->searchCalls,
        ];
    }

    private function needsBroaderPass(array $first, array $officialDomains): bool
    {
        $fit = array_filter($first['claims'], fn ($c) => ($c['requirement_field'] ?? null) === null);
        $requirements = array_filter($first['claims'], fn ($c) => ($c['requirement_field'] ?? null) !== null);

        return $officialDomains === []
            || ! $first['programme_found']
            || count($fit) < self::MIN_FIT_CLAIMS
            || $requirements === [];
    }

    /**
     * Why web research is not worth its cost for this order (null = research).
     * Only CVs are gated: without a named employer or a job-posting link there is
     * nothing to look up that the job description does not already say.
     */
    private function skipReason(StageContext $ctx): ?string
    {
        if (! $ctx->isResume()) {
            return null;
        }

        if (filled($ctx->order->institution) || preg_match(self::LINK, (string) $ctx->order->essay_prompt)) {
            return null;
        }

        foreach ($ctx->answers() as $answer) {
            if (! in_array($answer['section'], ['application', 'additional'], true)) {
                continue; // story answers (work history...) carry the applicant's own links, not a target
            }
            if (preg_match(self::LINK, $answer['answer'])) {
                return null;
            }
            // Only the order form's own fields name an employer; a follow-up question that merely asks
            // "is there an employer?" says nothing about the answer (a link in the answer still counts above).
            if (! str_starts_with($answer['key'], 'followup_') && preg_match(self::EMPLOYER_FIELD, str_replace('_', ' ', $answer['key']).' '.$answer['question'])) {
                return null;
            }
        }

        return 'CV order without a named target employer or job-posting link: tailored from the job description and the applicant\'s material, so no web research was needed.';
    }

    /** @return list<string> the applicant's interests and goals, for relevance only */
    private function interests(StageContext $ctx): array
    {
        $statements = [];
        foreach ($ctx->facts() as $fact) {
            if (in_array($fact['category'] ?? '', ['interest', 'motivation', 'career_goal', 'project'], true)) {
                $statements[] = (string) $fact['statement'];
            }
        }

        return array_slice($statements, 0, 8);
    }
}
