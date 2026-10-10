<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Llm\LlmCall;
use App\Domain\Ai\Llm\LlmGateway;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageFailure;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Ai\Samples\WritingSampleOverlap;
use App\Domain\Ai\Writing\DraftConverter;
use App\Domain\Ai\Writing\FactChecker;
use App\Domain\Ai\Writing\SentenceSplitter;
use App\Domain\Documents\DocumentModel;
use App\Models\ResearchClaim;

/**
 * Factual review: deterministic checks (numbers, names, URLs, placeholders,
 * wording copied from a writing sample) plus a model review of every
 * statement against the profile, the order and the verified dossier.
 * Problems are fixed by an LLM correction pass (up to
 * stages.fact_check.max_fix_passes, default 2); anything mechanical that
 * survives is removed sentence by sentence; a wrong institution or programme
 * name that survives sends the order to manual review. Claims the final text
 * uses are marked used_in_document.
 */
class FactCheckStage implements Stage
{
    private const ISSUE_TYPE_MAP = [
        FactChecker::UNSUPPORTED_NUMBER => 'wrong_date_or_number',
        FactChecker::URL_OR_CITATION => 'citation_or_url',
        FactChecker::PLACEHOLDER => 'other',
        FactChecker::UNKNOWN_INSTITUTION => 'wrong_institution_or_programme',
        FactChecker::UNKNOWN_PROGRAMME => 'wrong_institution_or_programme',
        WritingSampleOverlap::COPIED_FROM_SAMPLE => 'other',
    ];

    public function __construct(
        private readonly LlmGateway $llm,
        private readonly FactChecker $checker,
        private readonly WritingSampleOverlap $overlap,
    ) {}

    public function run(StageContext $ctx): StageResult
    {
        $draft = $ctx->currentDraft() ?? throw StageFailure::permanent('missing_draft', 'There is no draft to fact-check.');
        $evidence = $ctx->evidence();
        $names = ['institution' => $ctx->order->institution, 'programme' => $ctx->order->programme];
        $citationsAllowed = ($ctx->template()?->citation_style ?? 'none') !== 'none';
        $maxFixes = max(0, (int) $ctx->stageConfig('max_fix_passes', 2));
        $dossierKeys = $this->dossierKeys($ctx);
        $safeKeys = array_flip($ctx->safeClaimKeys());
        $usedClaims = $ctx->currentDraftClaimIds();
        $sampleTexts = $ctx->writingSamples()->map(fn ($sample) => (string) $sample->content)->all();
        $copied = fn (DocumentModel $draft): array => $this->overlap->find($draft, $sampleTexts, array_values($names));

        $history = [];
        $blocking = [];

        for ($pass = 0; ; $pass++) {
            $findings = [...$this->checker->check($draft, $evidence, $names, $citationsAllowed), ...$copied($draft)];

            $variables = PromptInputs::common($ctx);
            $variables['draft'] = DraftConverter::forPrompt($draft);
            $variables['automated_findings'] = $findings ?: 'None.';
            $variables['citations_allowed'] = $citationsAllowed;

            $review = $this->llm->call($ctx, new LlmCall(
                task: 'fact_check',
                variables: $variables,
                context: PromptInputs::fakeContext($ctx, $draft, ['findings' => $findings, 'used_claim_ids' => $usedClaims]),
            ));

            $usedClaims = array_values(array_intersect(array_map('strval', (array) $review->data['used_claim_ids']), $dossierKeys));
            $issues = $this->issues($findings, (array) $review->data['issues']);

            // A claim the text relies on that is not safe to use must go.
            foreach ($usedClaims as $key) {
                if (! isset($safeKeys[$key])) {
                    $issues[] = ['excerpt' => '', 'problem' => "The text uses research claim {$key}, which is not verified.", 'type' => 'unverified_research_claim', 'severity' => 'high', 'fix' => 'Remove the information taken from this claim.', 'origin' => 'model'];
                }
            }

            $blocking = array_values(array_filter($issues, fn ($i) => in_array($i['severity'], ['high', 'medium'], true)));
            $history[] = ['pass' => $pass + 1, 'automated' => count($findings), 'issues' => count($issues), 'blocking' => count($blocking)];

            if ($blocking === [] || $pass >= $maxFixes) {
                break;
            }

            $fix = $this->llm->call($ctx, new LlmCall(
                task: 'fact_fix',
                variables: PromptInputs::common($ctx) + ['draft' => DraftConverter::forPrompt($draft), 'issues' => $blocking],
                context: PromptInputs::fakeContext($ctx, $draft, ['issues' => $blocking, 'used_claim_ids' => $usedClaims]),
            ));
            $fixed = DraftConverter::toModel($fix->data, $draft->title, $ctx->languageVariant(), $ctx->applicantName());
            if ($fixed->wordCount() > 0) {
                $draft = $fixed;
            }
        }

        // Last resort: remove sentences that still carry blocking problems.
        $removed = 0;
        if ($blocking !== []) {
            [$draft, $removed] = $this->removeFlaggedSentences($draft, $blocking);
        }
        $remaining = $this->checker->check($draft, $evidence, $names, $citationsAllowed);
        if ($remaining !== []) {
            $before = count(SentenceSplitter::split($draft->bodyText()));
            $draft = $this->checker->sanitize($draft, $remaining);
            $removed += max(0, $before - count(SentenceSplitter::split($draft->bodyText())));
            $remaining = $this->checker->check($draft, $evidence, $names, $citationsAllowed);
        }
        if (($stillCopied = $copied($draft)) !== []) {
            [$draft, $dropped] = $this->removeFlaggedSentences($draft, $stillCopied);
            $removed += $dropped;
        }

        $usedSafe = array_values(array_filter($usedClaims, fn ($key) => isset($safeKeys[$key])));
        $this->markUsedClaims($ctx, $usedSafe);

        $output = [
            'document' => $draft->toArray(),
            'used_claim_ids' => $usedSafe,
            'passes' => $history,
            'removed_sentences' => $removed,
            'unresolved' => $remaining,
        ];

        if ($remaining !== []) {
            return StageResult::manualReview('fact_check_failed', 'The factual review could not resolve: '.implode(' ', array_slice(array_column($remaining, 'problem'), 0, 3)), $output);
        }

        if ($draft->wordCount() < 40) {
            return StageResult::manualReview('fact_check_gutted', 'Too little verifiable content remained after the factual review.', $output);
        }

        return StageResult::completed($output);
    }

    /** Deterministic findings plus model issues, in one shape. */
    private function issues(array $findings, array $modelIssues): array
    {
        $issues = array_map(fn ($f) => [
            'excerpt' => $f['excerpt'],
            'problem' => $f['problem'],
            'type' => self::ISSUE_TYPE_MAP[$f['type']] ?? 'other',
            'severity' => 'high',
            'fix' => $f['fix'] ?? 'Remove the detail or replace it with what the material supports.',
            'origin' => 'automated',
        ], $findings);

        foreach ($modelIssues as $issue) {
            $issues[] = [
                'excerpt' => (string) $issue['excerpt'],
                'problem' => (string) $issue['problem'],
                'type' => (string) $issue['type'],
                'severity' => (string) $issue['severity'],
                'fix' => (string) $issue['fix'],
                'origin' => 'model',
            ];
        }

        return $issues;
    }

    /** @return array{0:DocumentModel, 1:int} */
    private function removeFlaggedSentences(DocumentModel $draft, array $issues): array
    {
        $excerpts = array_values(array_filter(array_map(fn ($i) => trim((string) $i['excerpt']), $issues), fn ($e) => mb_strlen($e) >= 8));
        if ($excerpts === []) {
            return [$draft, 0];
        }

        $removed = 0;
        $blocks = [];
        foreach ($draft->blocks as $block) {
            if ($block['type'] !== 'paragraph') {
                $blocks[] = $block;

                continue;
            }

            $kept = [];
            foreach (SentenceSplitter::split($block['text']) as $sentence) {
                $flagged = false;
                foreach ($excerpts as $excerpt) {
                    if (str_contains($sentence, $excerpt) || str_contains($excerpt, $sentence)) {
                        $flagged = true;
                        break;
                    }
                }
                if ($flagged) {
                    $removed++;
                } else {
                    $kept[] = $sentence;
                }
            }
            if ($kept !== []) {
                $blocks[] = ['type' => 'paragraph', 'text' => implode(' ', $kept)];
            }
        }

        return [new DocumentModel($draft->title, $draft->subtitle, $draft->applicantName, $blocks, $draft->languageVariant, $draft->date), $removed];
    }

    /** @return list<string> every claim key of the dossier job (safe or not) */
    private function dossierKeys(StageContext $ctx): array
    {
        $jobId = $ctx->dossierJobId();

        return $jobId
            ? ResearchClaim::query()->where('order_id', $ctx->order->id)->where('ai_job_id', $jobId)->pluck('claim_key')->map(fn ($k) => (string) $k)->all()
            : [];
    }

    private function markUsedClaims(StageContext $ctx, array $usedKeys): void
    {
        $jobId = $ctx->dossierJobId();
        if (! $jobId) {
            return;
        }

        $query = ResearchClaim::query()->where('order_id', $ctx->order->id)->where('ai_job_id', $jobId);
        (clone $query)->update(['used_in_document' => false]);
        if ($usedKeys !== []) {
            (clone $query)->whereIn('claim_key', $usedKeys)->update(['used_in_document' => true]);
        }
    }
}
