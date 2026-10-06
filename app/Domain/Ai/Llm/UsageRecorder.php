<?php

namespace App\Domain\Ai\Llm;

use App\Domain\Ai\Pipeline\StageContext;
use App\Models\AiUsage;
use App\Models\PromptVersion;

/**
 * Writes one ai_usages row per model call (successful or not) and adds the
 * tokens, calls, searches and estimated cost to the job and step counters.
 */
class UsageRecorder
{
    public function __construct(private readonly CostEstimator $costs) {}

    public function record(
        StageContext $ctx,
        string $provider,
        string $model,
        ?PromptVersion $prompt,
        ?LlmResponse $response,
        string $status,
        ?string $errorType = null,
        int $durationMs = 0,
    ): float {
        $billedModel = $response && $response->model !== '' ? $response->model : $model;
        $cost = $response
            ? $this->costs->estimate($billedModel, $response->inputTokens, $response->cachedInputTokens, $response->outputTokens, $response->searchCalls)
            : 0.0;

        AiUsage::query()->create([
            'ai_job_id' => $ctx->job->id,
            'ai_job_step_id' => $ctx->step->id,
            'order_id' => $ctx->order->id,
            'service_id' => $ctx->order->service_id,
            'provider' => $provider,
            'model' => mb_substr($billedModel, 0, 80),
            'stage' => $ctx->stage->value,
            'prompt_version_id' => $prompt?->id,
            'response_id' => $response?->id ? mb_substr($response->id, 0, 100) : null,
            'input_tokens' => $response?->inputTokens ?? 0,
            'cached_input_tokens' => $response?->cachedInputTokens ?? 0,
            'output_tokens' => $response?->outputTokens ?? 0,
            'reasoning_tokens' => $response?->reasoningTokens ?? 0,
            'search_calls' => $response?->searchCalls ?? 0,
            'duration_ms' => $durationMs,
            'estimated_cost_usd' => $cost,
            'status' => mb_substr($status, 0, 20),
            'error_type' => $errorType ? mb_substr($errorType, 0, 60) : null,
            'created_at' => now(),
        ]);

        $costString = number_format($cost, 6, '.', '');

        $ctx->job->incrementEach([
            'llm_calls' => 1,
            'total_input_tokens' => $response?->inputTokens ?? 0,
            'total_cached_tokens' => $response?->cachedInputTokens ?? 0,
            'total_output_tokens' => $response?->outputTokens ?? 0,
            'total_reasoning_tokens' => $response?->reasoningTokens ?? 0,
            'search_calls' => $response?->searchCalls ?? 0,
            'total_cost_usd' => $costString,
        ]);

        $ctx->step->incrementEach([
            'input_tokens' => $response?->inputTokens ?? 0,
            'output_tokens' => $response?->outputTokens ?? 0,
            'cost_usd' => $costString,
        ]);

        if ($ctx->step->model === null) {
            $ctx->step->forceFill(['model' => mb_substr($model, 0, 80), 'prompt_version_id' => $prompt?->id])->save();
        }

        return $cost;
    }
}
