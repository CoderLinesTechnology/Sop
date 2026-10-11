<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Llm\LlmCall;
use App\Domain\Ai\Llm\LlmGateway;
use App\Domain\Ai\Llm\LlmResult;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageFailure;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Ai\Writing\AnswerCopyCheck;
use App\Domain\Ai\Writing\DraftConverter;
use App\Domain\Ai\Writing\StyleLinter;
use App\Domain\Documents\DocumentModel;
use App\Enums\PipelineStage;

/**
 * Produces document text in one of three modes:
 *  - initial:    write the draft from the strategy (order pipelines);
 *  - refinement: the quality review asked for a rewrite — apply its
 *                instructions, then fact_check (via editorial only when the
 *                style checks still find problems);
 *  - revision:   revision jobs — apply the customer's revision request to the
 *                delivered document (same conditional editorial pass).
 */
class WritingStage implements Stage
{
    private const MIN_WORDS = 40;

    public function __construct(
        private readonly LlmGateway $llm,
        private readonly StyleLinter $linter,
    ) {}

    public function run(StageContext $ctx): StageResult
    {
        $previous = $ctx->previousCompletedStep();
        if ($previous?->stage === PipelineStage::QualityReview && ! empty($previous->output['refine'])) {
            return $this->refine($ctx, (array) $previous->output);
        }

        return $ctx->isRevision() ? $this->revise($ctx) : $this->write($ctx);
    }

    private function write(StageContext $ctx): StageResult
    {
        $variables = PromptInputs::common($ctx);
        $variables['strategy'] = $ctx->output(PipelineStage::Strategy)['strategy'] ?? PromptInputs::analysis($ctx);

        $result = $this->llm->call($ctx, new LlmCall(
            task: 'writing',
            variables: $variables,
            context: PromptInputs::fakeContext($ctx, extra: ['strategy' => $variables['strategy']]),
            writingSamples: PromptInputs::writingSamples($ctx),
        ));

        return StageResult::completed($this->output($ctx, $result, 'initial'));
    }

    private function refine(StageContext $ctx, array $review): StageResult
    {
        $draft = $ctx->currentDraft() ?? throw StageFailure::permanent('missing_draft', 'There is no draft to refine.');

        $variables = PromptInputs::common($ctx);
        $variables['draft'] = DraftConverter::forPrompt($draft);
        $variables['review'] = array_intersect_key($review, array_flip(['overall', 'scores', 'weak_categories', 'answers_prompt', 'issues', 'instructions', 'strengths']));

        $result = $this->llm->call($ctx, new LlmCall(
            task: 'refinement',
            variables: $variables,
            // A targeted revision of an existing draft does not need the first draft's deep reasoning (about a minute faster per round).
            reasoningEffort: (string) $ctx->config('stages.writing.refinement_reasoning_effort', 'medium'),
            context: PromptInputs::fakeContext($ctx, $draft, ['review' => $variables['review'], 'used_claim_ids' => $ctx->currentDraftClaimIds()]),
            writingSamples: PromptInputs::writingSamples($ctx),
        ));

        // A refined draft is fact-checked and reviewed again. It gets another editorial pass only when the
        // free style checks still find real problems (long or uniform sentences, stiff wording...).
        $output = $this->output($ctx, $result, 'refinement') + ['round' => $review['round'] ?? null];

        return StageResult::completed($output, $this->needsEdit($ctx, $output) ? PipelineStage::Editorial : PipelineStage::FactCheck);
    }

    private function revise(StageContext $ctx): StageResult
    {
        $revision = $ctx->revision() ?? throw StageFailure::permanent('missing_revision', 'The revision request no longer exists.');
        $delivered = $ctx->currentDraft() ?? throw StageFailure::permanent('missing_delivered_version', 'No delivered document version was found to revise.');

        $variables = PromptInputs::common($ctx);
        $variables['delivered_document'] = DraftConverter::forPrompt($delivered);
        $variables['revision_request'] = (string) $revision->request_text;

        $result = $this->llm->call($ctx, new LlmCall(
            task: 'revision',
            variables: $variables,
            context: PromptInputs::fakeContext($ctx, $delivered, ['request' => (string) $revision->request_text]),
            writingSamples: PromptInputs::writingSamples($ctx),
        ));

        $output = $this->output($ctx, $result, 'revision') + ['revision_number' => $revision->number];

        return StageResult::completed($output, $this->needsEdit($ctx, $output) ? PipelineStage::Editorial : PipelineStage::FactCheck);
    }

    /**
     * Whether a rewritten draft still has real style problems (long or uniform sentences, stiff
     * wording, banned phrases...) for the editorial pass to fix; otherwise that model call is skipped.
     */
    private function needsEdit(StageContext $ctx, array $output): bool
    {
        $draft = DocumentModel::fromArray($output['document']);
        $findings = [
            ...$this->linter->lint($draft, $ctx->bannedPhrases(), $ctx->languageVariant(), array_filter([$ctx->order->institution, $ctx->order->programme]), $ctx->documentKind()),
            ...app(AnswerCopyCheck::class)->find($draft, array_column($ctx->answers(), 'answer')),
        ];

        return StyleLinter::needsEditorialPass($findings);
    }

    private function output(StageContext $ctx, LlmResult $result, string $mode): array
    {
        $draft = DraftConverter::toModel($result->data, $ctx->documentTypeLabel(), $ctx->languageVariant(), $ctx->applicantName());

        if ($draft->wordCount() < self::MIN_WORDS) {
            throw StageFailure::retryable('empty_draft', "The {$mode} draft was empty or too short ({$draft->wordCount()} words).");
        }

        return [
            'mode' => $mode,
            'document' => $draft->toArray(),
            'word_count' => $draft->wordCount(),
            'character_count' => $draft->characterCount(),
            'used_fact_ids' => array_values(array_intersect(array_map('strval', (array) $result->data['used_fact_ids']), $ctx->factIds())),
            'used_claim_ids' => array_values(array_intersect(array_map('strval', (array) $result->data['used_claim_ids']), $ctx->safeClaimKeys())),
            'notes' => (string) $result->data['notes'],
        ];
    }
}
