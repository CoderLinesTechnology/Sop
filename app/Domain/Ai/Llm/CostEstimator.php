<?php

namespace App\Domain\Ai\Llm;

use App\Models\AiModelPrice;
use Illuminate\Support\Facades\Log;

/**
 * Estimates the USD cost of a call from ai_model_prices.
 *
 * cost = uncached input × input price + cached input × cached price
 *      + output × output price (output tokens already include reasoning
 *        tokens) + web search calls × per-call price.
 *
 * Model ids returned by the API may carry a date suffix
 * ("gpt-6-astra-2026-08-01"), so the longest matching price prefix is used.
 * Unknown models are priced at the most expensive active model (and logged),
 * so budgets stay conservative.
 */
class CostEstimator
{
    /** @var array<string, AiModelPrice>|null */
    private ?array $prices = null;

    private int $loadedAt = 0;

    public function estimate(string $model, int $inputTokens, int $cachedTokens, int $outputTokens, int $searchCalls = 0): float
    {
        $price = $this->priceFor($model);
        if (! $price) {
            return 0.0;
        }

        $cached = min($cachedTokens, $inputTokens);
        $uncached = max(0, $inputTokens - $cached);
        $cachedRate = $price->cached_input_per_million !== null ? (float) $price->cached_input_per_million : (float) $price->input_per_million;

        $cost = $uncached * (float) $price->input_per_million / 1_000_000
            + $cached * $cachedRate / 1_000_000
            + $outputTokens * (float) $price->output_per_million / 1_000_000
            + $searchCalls * (float) $price->web_search_per_call;

        return round($cost, 6);
    }

    public function priceFor(string $model): ?AiModelPrice
    {
        $prices = $this->prices();
        if ($prices === []) {
            return null;
        }

        $model = strtolower(trim($model));
        if (isset($prices[$model])) {
            return $prices[$model];
        }

        $best = null;
        foreach ($prices as $name => $price) {
            if (str_starts_with($model, $name.'-') && ($best === null || strlen($name) > strlen($best))) {
                $best = $name;
            }
        }
        if ($best !== null) {
            return $prices[$best];
        }

        Log::warning('No AI price configured for model; using the most expensive active price.', ['model' => $model]);

        return collect($prices)->sortByDesc(fn (AiModelPrice $p) => (float) $p->output_per_million + (float) $p->input_per_million)->first();
    }

    public function flush(): void
    {
        $this->prices = null;
    }

    /** @return array<string, AiModelPrice> */
    private function prices(): array
    {
        // Workers are long-lived: refresh the price table every five minutes.
        if ($this->prices === null || time() - $this->loadedAt > 300) {
            $this->prices = AiModelPrice::query()->where('is_active', true)->get()
                ->keyBy(fn (AiModelPrice $p) => strtolower($p->model))
                ->all();
            $this->loadedAt = time();
        }

        return $this->prices;
    }
}
