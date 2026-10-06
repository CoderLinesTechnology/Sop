<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Llm\LlmCall;
use App\Domain\Ai\Llm\LlmGateway;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageResult;

/**
 * Internal narrative strategy: opening, evidence (fact ids), development,
 * programme fit (safe claim ids only), goals, contribution, conclusion and a
 * paragraph plan with word allocation. References to unknown facts or unsafe
 * claims are removed before the plan reaches the writer.
 */
class StrategyStage implements Stage
{
    public function __construct(private readonly LlmGateway $llm) {}

    public function run(StageContext $ctx): StageResult
    {
        $variables = PromptInputs::common($ctx);
        $variables['analysis'] = PromptInputs::analysis($ctx);

        $result = $this->llm->call($ctx, new LlmCall(
            task: 'strategy',
            variables: $variables,
            context: PromptInputs::fakeContext($ctx),
        ));

        [$strategy, $dropped] = $this->sanitize($result->data, $ctx->factIds(), $ctx->safeClaimKeys(), $ctx->targetWords());

        return StageResult::completed(['strategy' => $strategy, 'dropped_references' => $dropped]);
    }

    /** @return array{0:array, 1:int} */
    private function sanitize(array $strategy, array $factIds, array $claimIds, int $targetWords): array
    {
        $facts = array_flip($factIds);
        $claims = array_flip($claimIds);
        $dropped = 0;

        $filter = function (array $ids, array $known) use (&$dropped): array {
            $kept = array_values(array_filter(array_map('strval', $ids), fn ($id) => isset($known[$id])));
            $dropped += count($ids) - count($kept);

            return $kept;
        };

        $strategy['opening']['fact_ids'] = $filter((array) $strategy['opening']['fact_ids'], $facts);
        foreach (['future_goals', 'contribution'] as $key) {
            $strategy[$key]['fact_ids'] = $filter((array) $strategy[$key]['fact_ids'], $facts);
        }
        foreach ($strategy['evidence'] as $i => $point) {
            $strategy['evidence'][$i]['fact_ids'] = $filter((array) $point['fact_ids'], $facts);
        }
        foreach ($strategy['programme_fit'] as $i => $point) {
            $strategy['programme_fit'][$i]['fact_ids'] = $filter((array) $point['fact_ids'], $facts);
            $strategy['programme_fit'][$i]['claim_ids'] = $filter((array) $point['claim_ids'], $claims);
        }

        $total = 0;
        foreach ($strategy['paragraph_plan'] as $i => $paragraph) {
            $strategy['paragraph_plan'][$i]['fact_ids'] = $filter((array) $paragraph['fact_ids'], $facts);
            $strategy['paragraph_plan'][$i]['claim_ids'] = $filter((array) $paragraph['claim_ids'], $claims);
            $total += (int) $paragraph['target_words'];
        }

        // Re-scale the word allocation so the plan adds up to the target.
        if ($total > 0 && $targetWords > 0 && abs($total - $targetWords) > $targetWords * 0.1) {
            foreach ($strategy['paragraph_plan'] as $i => $paragraph) {
                $strategy['paragraph_plan'][$i]['target_words'] = max(20, (int) round($paragraph['target_words'] * $targetWords / $total));
            }
        }

        return [$strategy, $dropped];
    }
}
