<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Llm\LlmCall;
use App\Domain\Ai\Llm\LlmGateway;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageFailure;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Ai\Writing\DraftConverter;
use App\Domain\Ai\Writing\StyleLinter;

/**
 * Separate editorial / humanisation pass: the deterministic style linter's
 * findings (banned phrases, em dashes, repeated openings, monotonous rhythm,
 * variant spelling...) are handed to an editor prompt that improves rhythm,
 * repetition, transitions and voice while preserving every fact. An edit that
 * loses too much content is discarded in favour of the original draft.
 */
class EditorialStage implements Stage
{
    /** An edit may not shrink the draft below this share of its words. */
    private const MIN_RETAINED = 0.8;

    public function __construct(
        private readonly LlmGateway $llm,
        private readonly StyleLinter $linter,
    ) {}

    public function run(StageContext $ctx): StageResult
    {
        $draft = $ctx->currentDraft() ?? throw StageFailure::permanent('missing_draft', 'There is no draft to edit.');
        $protected = array_filter([$ctx->order->institution, $ctx->order->programme]);
        $before = $this->linter->lint($draft, $ctx->bannedPhrases(), $ctx->languageVariant(), $protected);

        $variables = PromptInputs::common($ctx);
        $variables['draft'] = DraftConverter::forPrompt($draft);
        $variables['style_findings'] = $before ?: 'No automated findings.';

        $result = $this->llm->call($ctx, new LlmCall(
            task: 'editorial',
            variables: $variables,
            context: PromptInputs::fakeContext($ctx, $draft, ['findings' => $before, 'used_claim_ids' => $ctx->currentDraftClaimIds()]),
            writingSamples: PromptInputs::writingSamples($ctx),
        ));

        $edited = DraftConverter::toModel($result->data, $draft->title, $ctx->languageVariant(), $ctx->applicantName());
        $discarded = $edited->wordCount() < $draft->wordCount() * self::MIN_RETAINED;
        $final = $discarded ? $draft : $edited;

        $usedClaims = array_values(array_intersect(array_map('strval', (array) $result->data['used_claim_ids']), $ctx->safeClaimKeys()))
            ?: $ctx->currentDraftClaimIds();

        return StageResult::completed([
            'document' => $final->toArray(),
            'used_claim_ids' => $usedClaims,
            'findings_before' => $before,
            'findings_after' => $this->linter->lint($final, $ctx->bannedPhrases(), $ctx->languageVariant(), $protected),
            'edit_discarded' => $discarded,
            'notes' => (string) $result->data['notes'],
        ]);
    }
}
