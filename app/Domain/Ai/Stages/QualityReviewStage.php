<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Llm\LlmCall;
use App\Domain\Ai\Llm\LlmGateway;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageFailure;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Ai\Writing\DraftConverter;
use App\Domain\Documents\DocumentModel;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Models\AiJob;
use App\Models\AiJobStep;
use App\Models\QualityReview;

/**
 * Prompt-adherence check and internal 0–10 scores for every quality
 * category. The overall score is computed here (mean of the categories), not
 * taken from the model. A document passes when overall >= quality.threshold,
 * every category >= quality.min_category_score and the prompt is answered.
 * Otherwise it goes back for refinement (writing → [editorial] → fact_check → review) up to
 * quality.max_refinement_rounds. When the rounds are used up, the best-scoring
 * reviewed draft is delivered (quality.auto_deliver) unless it is unsafe to
 * send: the prompt not answered, or the overall, any category or the factual
 * accuracy below the delivery floors. Only those go to manual review.
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

        $threshold = (float) $ctx->config('quality.threshold', 8.1);
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

        // The AI fix rounds are used up: send the best reviewed draft unless it is unsafe to deliver.
        $reviewed = $this->reviewedDrafts($ctx, $draft, $output);
        $safe = array_filter($reviewed, fn (array $r) => $this->deliveryBlockers($ctx, $r['review']) === []);
        if ($safe !== [] && (bool) $ctx->config('quality.auto_deliver', true)) {
            // Highest overall score; on a tie the later, more refined draft.
            $best = array_reduce($safe, fn (?array $carry, array $r) => ($carry === null || $r['review']['overall'] >= $carry['review']['overall']) ? $r : $carry);

            return StageResult::completed($output + [
                'document' => $best['document'],
                'used_claim_ids' => $best['used_claim_ids'],
                'delivered_below_bar' => true,
                'delivered_round' => (int) $best['review']['round'],
                'delivered_score' => (float) $best['review']['overall'],
            ]);
        }

        $reasons = $this->deliveryBlockers($ctx, $output);
        if ($overall < $threshold) {
            array_unshift($reasons, sprintf('overall %.2f below %.2f', $overall, $threshold));
        }
        if ($weak !== []) {
            $reasons[] = 'weak: '.implode(', ', $weak);
        }

        return StageResult::manualReview(
            'quality_below_threshold',
            sprintf('The document did not reach the quality bar after %d refinement round(s) (%s).', $roundsUsed, implode('; ', array_unique($reasons))),
            $output,
        );
    }

    /**
     * Why a reviewed draft must not be sent without a person looking at it.
     *
     * @param  array{overall: float, scores: array<string, float>, answers_prompt: bool}  $review
     * @return list<string>
     */
    private function deliveryBlockers(StageContext $ctx, array $review): array
    {
        $floor = (float) $ctx->config('quality.delivery_floor', 7.0);
        $categoryFloor = (float) $ctx->config('quality.delivery_min_category', 5.0);
        $factualFloor = (float) $ctx->config('quality.delivery_min_factual_accuracy', 8.0);
        $scores = (array) $review['scores'];

        $blockers = [];
        if (! $review['answers_prompt']) {
            $blockers[] = 'does not fully answer the prompt';
        }
        if ((float) $review['overall'] < $floor) {
            $blockers[] = sprintf('overall %.2f below the delivery floor %.2f', $review['overall'], $floor);
        }
        if ((float) ($scores['factual_accuracy'] ?? 0) < $factualFloor) {
            $blockers[] = sprintf('factual accuracy %.1f below %.1f', $scores['factual_accuracy'] ?? 0, $factualFloor);
        }
        if (($low = array_keys(array_filter($scores, fn ($score) => (float) $score < $categoryFloor))) !== []) {
            $blockers[] = 'very weak: '.implode(', ', $low);
        }

        return $blockers;
    }

    /**
     * Every draft this job has reviewed, with its review output, the current one last. A review
     * judged the latest draft completed before it.
     *
     * @return list<array{document: array, used_claim_ids: list<string>, review: array}>
     */
    private function reviewedDrafts(StageContext $ctx, DocumentModel $current, array $currentReview): array
    {
        $steps = AiJobStep::query()
            ->where('ai_job_id', $ctx->job->id)
            ->where('id', '<', $ctx->step->id)
            ->where('status', StepStatus::Completed->value)
            // Only the stages that produce or review a draft (skips the large research outputs).
            ->whereIn('stage', [PipelineStage::Writing->value, PipelineStage::Editorial->value, PipelineStage::FactCheck->value, PipelineStage::QualityReview->value])
            ->orderBy('id')
            ->get(['id', 'stage', 'output']);

        $reviewed = [];
        $latest = null;
        foreach ($steps as $step) {
            $output = (array) $step->output;
            if (is_array($output['document'] ?? null)) {
                $latest = ['document' => $output['document'], 'used_claim_ids' => array_values(array_map('strval', (array) ($output['used_claim_ids'] ?? [])))];
            } elseif ($step->stage === PipelineStage::QualityReview && $latest !== null && isset($output['overall'], $output['scores'])) {
                $reviewed[] = $latest + ['review' => $output + ['answers_prompt' => false, 'round' => 0]];
            }
        }

        $reviewed[] = ['document' => $current->toArray(), 'used_claim_ids' => $ctx->currentDraftClaimIds(), 'review' => $currentReview];

        return $reviewed;
    }
}
