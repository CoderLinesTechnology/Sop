<?php

namespace App\Domain\Ai\Llm;

use RuntimeException;

/**
 * A job (or the platform-wide daily budget) has hit a configured limit.
 * The runner reacts according to the workflow's on_budget_exceeded setting.
 */
final class BudgetExceeded extends RuntimeException
{
    public function __construct(string $message, public readonly string $limit)
    {
        parent::__construct($message);
    }

    public function isGlobal(): bool
    {
        return $this->limit === 'daily_budget';
    }
}
