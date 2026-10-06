<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Llm\LlmCall;
use App\Domain\Ai\Llm\LlmGateway;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageFailure;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Ai\Writing\DraftConverter;
use App\Enums\PipelineStage;
use App\Models\AiJob;
use App\Models\QualityReview;

/**
 * Prompt-adherence check and internal 0–10 scores for every quality
 * category. The overall score is computed here (mean of the categories), not
 * taken from the model. A document passes when overall >= quality.threshold,
 * every category >= quality.min_category_score and the prompt is answered.
 * Otherwise it goes back for refinement (writing → fact_check → review) up to
 * quality.max_refinement_rounds; a document that still fails is never
 * delivered — the order goes to manual review.
 */
class QualityReviewStage implements Stage
{
    public function __construct(private readonly LlmGateway $llm) {}

    public function run(StageContext $ctx): StageResult
    {
        $draft = $ctx->currentDraft() ?? throw StageFailure::permanent('missing_draft', 'There is no draft to review.');

        $variables = PromptInputs::common($ctx);
        $variables['draft'] = DraftConverter::forPrompt($draft);

        $round = QualityReview::query()->where('ai_job_id', $ctx->job->id)->count() + 1;

        $result = $this->llm->call($ctx, new LlmCall(
            task: 'quality_review',
            variables: $variables,
            context: PromptInputs::fakeContext($ctx, $draft, ['round' => $round]),
        ));

        $scores = [];
        foreach (array_keys(QualityReview::CATEGORIES) as $category) {
            $scores[$category] = round(min(10, max(0, (float) ($result->data['scores'][$category] ?? 0))), 1);
        }
        $overall = round(array_sum($scores) / count($scores), 2);

        $threshold = (float) $ctx->config('quality.threshold', 8.0);
        $minimum = (float) $ctx->config('quality.min_category_score', 6.5);
        $answersPrompt = (bool) $result->data['answers_prompt'];
        $weak = array_keys(array_filter($scores, fn ($score) => $score < $minimum));
        $passed = $overall >= $threshold && $weak === [] && $answersPrompt;

        QualityReview::query()->create([
            'ai_job_id' => $ctx->job->id,
            'order_id' => $ctx->order->id,
            'round' => min(255, $round),
            'scores' => $scores,
            'overall_score' => $overall,
            'threshold' => $threshold,
            'passed' => $passed,
            'answers_prompt' => $answersPrompt,
            'issues' => $result->data['issues'] ?: null,
            'instructions' => (string) $result->data['instructions'],
            'reviewer' => 'ai',
            'model' => mb_substr($result->model, 0, 80),
        ]);

        $output = [
            'round' => $round,
            'passed' => $passed,
            'overall' => $overall,
            'threshold' => $threshold,
            'scores' => $scores,
            'weak_categories' => $weak,
            'answers_prompt' => $answersPrompt,
            'prompt_adherence_notes' => (string) $result->data['prompt_adherence_notes'],
            'issues' => $result->data['issues'],
            'instructions' => (string) $result->data['instructions'],
            'strengths' => $result->data['strengths'],
        ];

        if ($passed) {
            return StageResult::completed($output);
        }

        $maxRounds = max(0, (int) $ctx->config('quality.max_refinement_rounds', 2));
        $roundsUsed = (int) AiJob::query()->whereKey($ctx->job->id)->toBase()->value('refinement_rounds');

        if ($roundsUsed < $maxRounds) {
            AiJob::query()->whereKey($ctx->job->id)->increment('refinement_rounds');

            return StageResult::completed($output + ['refine' => true], PipelineStage::Writing);
        }

        $reasons = [];
        if ($overall < $threshold) {
            $reasons[] = sprintf('overall %.2f below %.2f', $overall, $threshold);
        }
        if ($weak !== []) {
            $reasons[] = 'weak: '.implode(', ', $weak);
        }
        if (! $answersPrompt) {
            $reasons[] = 'does not fully answer the prompt';
        }

        return StageResult::manualReview(
            'quality_below_threshold',
            sprintf('The document did not reach the quality bar after %d refinement round(s) (%s).', $roundsUsed, implode('; ', $reasons)),
            $output,
        );
    }
}
