<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Llm\LlmCall;
use App\Domain\Ai\Llm\LlmGateway;
use App\Domain\Ai\Llm\LlmResult;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageFailure;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Ai\Writing\DraftConverter;
use App\Enums\PipelineStage;

/**
 * Produces document text in one of three modes:
 *  - initial:    write the draft from the strategy (order pipelines);
 *  - refinement: the quality review asked for a rewrite — apply its
 *                instructions, then go straight back to fact_check;
 *  - revision:   revision jobs — apply the customer's revision request to the
 *                delivered document.
 */
class WritingStage implements Stage
{
    private const MIN_WORDS = 40;

    public function __construct(private readonly LlmGateway $llm) {}

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
            context: PromptInputs::fakeContext($ctx, $draft, ['review' => $variables['review'], 'used_claim_ids' => $ctx->currentDraftClaimIds()]),
        ));

        // A refined draft is fact-checked and reviewed again (editorial is not repeated).
        return StageResult::completed($this->output($ctx, $result, 'refinement') + ['round' => $review['round'] ?? null], PipelineStage::FactCheck);
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
        ));

        return StageResult::completed($this->output($ctx, $result, 'revision') + ['revision_number' => $revision->number]);
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
