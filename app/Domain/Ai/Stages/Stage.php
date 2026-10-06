<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Llm\BudgetExceeded;
use App\Domain\Ai\Llm\LlmException;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageFailure;
use App\Domain\Ai\Pipeline\StageResult;

/**
 * One pipeline stage. Handlers must be idempotent: a failed or interrupted
 * attempt is simply run again (they clean up rows written by an earlier
 * attempt of the same job where needed).
 */
interface Stage
{
    /**
     * @throws StageFailure
     * @throws LlmException
     * @throws BudgetExceeded
     */
    public function run(StageContext $ctx): StageResult;
}
