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
 */
class ResearchStage implements Stage
{
    private const MIN_FIT_CLAIMS = 2;

    private const MAX_CLAIMS_PER_PASS = 25;

    public function __construct(private readonly LlmGateway $llm) {}

    public function run(StageContext $ctx): StageResult
    {
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
