<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Llm\LlmCall;
use App\Domain\Ai\Llm\LlmGateway;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Ai\Research\PageFetcher;
use App\Domain\Ai\Research\QuoteMatcher;
use App\Domain\Ai\Research\SourceClassifier;
use App\Domain\Ai\Research\UrlNormalizer;
use App\Enums\ClaimVerificationStatus as V;
use App\Enums\PipelineStage;
use App\Models\ResearchClaim;
use App\Models\ResearchSource;
use Illuminate\Support\Facades\Log;

/**
 * Builds the Research Dossier (research_sources + research_claims).
 *
 * A claim is verified only if
 *  (a) its quote is found on the source page fetched through SafeHttpClient
 *      (normalised fuzzy match), or
 *  (b) the URL was actually returned by web search, the source is official
 *      and the model's confidence is high (>= 0.8).
 * A model review then checks that each quote really supports its claim and
 * finds contradictions. Conflicts are resolved by authority (official sources
 * win); when the most authoritative verified sources disagree, every claim in
 * the group is marked conflicting and nothing uncertain is stated.
 * safe_to_use = verified AND official. Unsafe claims never reach the writer.
 */
class VerificationStage implements Stage
{
    private const MAX_FETCHES = 20;

    private const HIGH_CONFIDENCE = 0.8;

    private const PARTIAL_MATCH = 0.6;

    public function __construct(
        private readonly LlmGateway $llm,
        private readonly PageFetcher $pages,
        private readonly QuoteMatcher $quotes,
        private readonly SourceClassifier $classifier,
    ) {}

    public function run(StageContext $ctx): StageResult
    {
        $research = $ctx->output(PipelineStage::Research);
        $claims = (array) ($research['claims'] ?? []);

        // Idempotent: a previous attempt of this stage may have written rows.
        ResearchClaim::query()->where('order_id', $ctx->order->id)->where('ai_job_id', $ctx->job->id)->delete();

        if ($claims === []) {
            return StageResult::completed(['claims' => 0, 'verified' => 0, 'safe' => 0, 'requirements' => [], 'conflicts' => []]);
        }

        $official = (array) ($research['official_domains'] ?? []);
        $seen = array_flip((array) ($research['seen_urls'] ?? []));
        $verifyQuotes = (bool) $ctx->config('research.verify_quotes', true);
        $fetched = [];
        $rows = [];

        foreach ($claims as $claim) {
            $url = $claim['source_url_normalized'] ?? UrlNormalizer::normalize((string) $claim['source_url']);
            if ($url === null) {
                continue;
            }

            $class = $this->classifier->classify($url, $official, $claim['source_type'] ?? null);
            $source = $this->source($ctx, $url, (string) ($claim['source_title'] ?? ''), $class);
            $quote = trim((string) ($claim['supporting_quote'] ?? ''));
            $notes = [];
            $status = V::Unverified;
            $method = null;
            $pageChecked = false;
            $partial = false;

            if ($verifyQuotes && $quote !== '' && (isset($fetched[$url]) || count($fetched) < self::MAX_FETCHES)) {
                if (! isset($fetched[$url])) {
                    $ctx->heartbeat();
                }
                $page = $fetched[$url] ??= $this->fetch($source, $url);

                if ($page['status'] === 'ok') {
                    $pageChecked = true;
                    $score = $this->quotes->score($quote, (string) $page['text']);
                    if ($score >= QuoteMatcher::THRESHOLD) {
                        $status = V::Verified;
                        $method = 'quote_on_page';
                    } elseif ($score >= self::PARTIAL_MATCH) {
                        $partial = true;
                        $notes[] = "Quote only partly found on the page (match {$score}).";
                    } else {
                        $notes[] = 'Quote not found on the fetched page.';
                    }
                } elseif ($page['status'] !== 'skipped') {
                    $notes[] = 'Source page could not be fetched ('.$page['status'].($page['error'] ? ': '.$page['error'] : '').').';
                }
            }

            $wasSeen = isset($seen[$url]);
            $confidence = (float) ($claim['confidence'] ?? 0);

            if ($status !== V::Verified && $wasSeen && $class['official'] && $confidence >= self::HIGH_CONFIDENCE) {
                $status = V::Verified;
                $method = 'official_search_result';
                $notes[] = 'Accepted: official page returned by web search, high confidence.';
            }

            if ($status !== V::Verified) {
                if ($partial) {
                    $status = V::PartiallyVerified;
                } elseif (! $wasSeen && ($pageChecked || $quote === '')) {
                    $status = V::Rejected;
                    $notes[] = 'The URL was not among the search results and the quote could not be confirmed.';
                }
            }

            $rows[] = [
                'claim' => $claim,
                'source' => $source,
                'class' => $class,
                'status' => $status,
                'method' => $method,
                'notes' => $notes,
                'conflict_group' => null,
            ];
        }

        $review = $this->modelReview($ctx, $rows);
        $conflicts = $this->resolveConflicts($rows, $review['conflicts']);

        $requirements = [];
        $safe = 0;
        foreach ($rows as $row) {
            $claim = $row['claim'];
            $isSafe = $row['status'] === V::Verified && $row['class']['official'];
            $safe += $isSafe ? 1 : 0;

            ResearchClaim::query()->create([
                'order_id' => $ctx->order->id,
                'ai_job_id' => $ctx->job->id,
                'research_source_id' => $row['source']->id,
                'claim_key' => mb_substr((string) $claim['id'], 0, 20),
                'claim' => (string) $claim['claim'],
                'category' => mb_substr((string) ($claim['category'] ?? 'other'), 0, 40),
                'supporting_quote' => $claim['supporting_quote'] ?? null,
                'confidence' => round(min(1, max(0, (float) ($claim['confidence'] ?? 0))), 2),
                'relevance' => $claim['relevance'] ?? null,
                'relevance_score' => round(min(1, max(0, (float) ($claim['relevance_score'] ?? 0))), 2),
                'verification_status' => $row['status'],
                'verification_method' => $row['method'],
                'verification_notes' => $row['notes'] ? mb_substr(implode(' ', $row['notes']), 0, 2000) : null,
                'safe_to_use' => $isSafe,
                'conflict_group' => $row['conflict_group'],
                'used_in_document' => false,
            ]);

            if (! empty($claim['requirement_field'])) {
                $requirements[(string) $claim['id']] = ['field' => $claim['requirement_field'], 'value' => $claim['requirement_value']];
            }
        }

        $counts = array_count_values(array_map(fn ($r) => $r['status']->value, $rows));

        // Every source failing to load means verification cannot work at all (and the writer gets no
        // programme facts): that is a fault on our side, not a property of the pages, so make it visible.
        $loaded = count(array_filter($fetched, fn (array $page) => $page['status'] === 'ok'));
        if (count($fetched) >= 3 && $loaded === 0) {
            $errors = array_values(array_unique(array_filter(array_map(fn (array $page) => $page['error'] ? mb_substr((string) $page['error'], 0, 200) : null, $fetched))));
            Log::warning('No research source page could be fetched; claims stay unverified.', [
                'order' => $ctx->order->reference,
                'pages' => count($fetched),
                'errors' => array_slice($errors, 0, 3),
            ]);
        }

        return StageResult::completed([
            'claims' => count($rows),
            'verified' => $counts[V::Verified->value] ?? 0,
            'partially_verified' => $counts[V::PartiallyVerified->value] ?? 0,
            'unverified' => $counts[V::Unverified->value] ?? 0,
            'rejected' => $counts[V::Rejected->value] ?? 0,
            'conflicting' => $counts[V::Conflicting->value] ?? 0,
            'safe' => $safe,
            'pages_fetched' => count($fetched),
            'requirements' => $requirements,
            'conflicts' => $conflicts,
            'review' => $review['summary'],
        ]);
    }

    private function source(StageContext $ctx, string $url, string $title, array $class): ResearchSource
    {
        return ResearchSource::query()->updateOrCreate(
            ['order_id' => $ctx->order->id, 'url_hash' => UrlNormalizer::hash($url)],
            [
                'ai_job_id' => $ctx->job->id,
                'url' => mb_substr($url, 0, 2048),
                'domain' => mb_substr((string) ($class['domain'] ?? UrlNormalizer::domain($url) ?? ''), 0, 255),
                'title' => $title !== '' ? mb_substr($title, 0, 255) : null,
                'source_type' => $class['type'],
                'is_official' => $class['official'],
                'authority_rank' => $class['rank'],
            ],
        );
    }

    /** Fetch a source page (once per stage run) and record the result on the source. */
    private function fetch(ResearchSource $source, string $url): array
    {
        $page = $this->pages->fetch($url);

        $source->forceFill([
            'fetch_status' => $page['status'],
            'http_status' => $page['http_status'],
            'content_hash' => $page['content_hash'],
            'retrieved_at' => $page['status'] === 'skipped' ? $source->retrieved_at : now(),
        ])->save();

        return $page;
    }

    /**
     * Model check that quotes support their claims, plus contradiction detection.
     *
     * @return array{conflicts: list<array{claim_ids:list<string>, description:string}>, summary: array}
     */
    private function modelReview(StageContext $ctx, array &$rows): array
    {
        $reviewable = array_filter($rows, fn ($r) => in_array($r['status'], [V::Verified, V::PartiallyVerified], true));
        if ($reviewable === [] || ! $ctx->stageConfig('model_review', true)) {
            return ['conflicts' => [], 'summary' => ['reviewed' => 0]];
        }

        $claims = array_values(array_map(fn ($r) => [
            'claim_id' => (string) $r['claim']['id'],
            'claim' => (string) $r['claim']['claim'],
            'category' => (string) ($r['claim']['category'] ?? ''),
            'requirement' => $r['claim']['requirement_field'] ? $r['claim']['requirement_field'].' = '.$r['claim']['requirement_value'] : null,
            'source' => $r['source']->url,
            'source_type' => $r['class']['type']->value,
            'quote' => (string) ($r['claim']['supporting_quote'] ?? ''),
        ], $reviewable));

        $result = $this->llm->call($ctx, new LlmCall(
            task: 'verification',
            variables: ['order_details' => $ctx->orderDetails(), 'claims' => $claims],
            context: ['claims' => $claims],
        ));

        $verdicts = [];
        foreach ((array) $result->data['reviews'] as $review) {
            $verdicts[(string) $review['claim_id']] = $review;
        }

        $unsupported = 0;
        foreach ($rows as &$row) {
            $verdict = $verdicts[(string) $row['claim']['id']] ?? null;
            if ($verdict && ! $verdict['supported'] && in_array($row['status'], [V::Verified, V::PartiallyVerified], true)) {
                $row['status'] = V::PartiallyVerified;
                $row['notes'][] = 'Model review: the quote does not fully support the claim. '.mb_substr((string) $verdict['notes'], 0, 300);
                $unsupported++;
            }
        }
        unset($row);

        return [
            'conflicts' => array_values(array_filter((array) $result->data['conflicts'], fn ($c) => count((array) $c['claim_ids']) > 1)),
            'summary' => ['reviewed' => count($claims), 'unsupported' => $unsupported],
        ];
    }

    /**
     * Requirement claims for the same field with different values, plus the
     * model's contradiction groups. Authority decides; ties stay unresolved.
     */
    private function resolveConflicts(array &$rows, array $modelConflicts): array
    {
        $index = [];
        foreach ($rows as $i => $row) {
            $index[(string) $row['claim']['id']] = $i;
        }

        $groups = [];
        $byField = [];
        foreach ($rows as $i => $row) {
            $field = $row['claim']['requirement_field'] ?? null;
            if ($field && in_array($row['status'], [V::Verified, V::PartiallyVerified], true)) {
                $byField[$field][$i] = $this->normaliseValue((string) $row['claim']['requirement_value']);
            }
        }
        foreach ($byField as $field => $values) {
            if (count(array_unique($values)) > 1) {
                $groups['req:'.$field] = ['members' => array_keys($values), 'description' => "Different values found for {$field}.", 'values' => $values];
            }
        }

        foreach ($modelConflicts as $n => $conflict) {
            $members = array_values(array_filter(array_map(fn ($id) => $index[(string) $id] ?? null, (array) $conflict['claim_ids']), fn ($i) => $i !== null));
            if (count($members) > 1) {
                $groups['conflict:'.($n + 1)] = ['members' => $members, 'description' => (string) $conflict['description'], 'values' => null];
            }
        }

        $summary = [];
        foreach ($groups as $name => $group) {
            $verified = array_values(array_filter($group['members'], fn ($i) => $rows[$i]['status'] === V::Verified));
            $bestRank = $verified ? min(array_map(fn ($i) => $rows[$i]['class']['rank'], $verified)) : null;
            $top = array_values(array_filter($verified, fn ($i) => $rows[$i]['class']['rank'] === $bestRank));

            $winner = null;
            if (count($top) === 1) {
                $winner = $top[0];
            } elseif (count($top) > 1 && $group['values'] !== null && count(array_unique(array_map(fn ($i) => $group['values'][$i], $top))) === 1) {
                $winner = $top[0]; // equally authoritative sources that agree
            }

            foreach ($group['members'] as $i) {
                $rows[$i]['conflict_group'] = mb_substr($name, 0, 40);
                $agreesWithWinner = $winner !== null && $group['values'] !== null && ($group['values'][$i] ?? null) === $group['values'][$winner];
                if ($i !== $winner && ! $agreesWithWinner) {
                    $rows[$i]['status'] = V::Conflicting;
                    $rows[$i]['notes'][] = $winner === null
                        ? 'Conflicting sources of equal authority; not used.'
                        : 'Conflicts with a more authoritative source (claim '.$rows[$winner]['claim']['id'].').';
                }
            }

            $summary[] = [
                'group' => $name,
                'claim_ids' => array_map(fn ($i) => (string) $rows[$i]['claim']['id'], $group['members']),
                'description' => $group['description'],
                'winner' => $winner !== null ? (string) $rows[$winner]['claim']['id'] : null,
            ];
        }

        return $summary;
    }

    private function normaliseValue(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if (preg_match('/\d[\d,]*/', $value, $m)) {
            return str_replace(',', '', $m[0]);
        }

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }
}
