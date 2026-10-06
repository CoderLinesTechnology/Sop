<?php

namespace App\Domain\Ai\Pipeline;

use App\Domain\Ai\Llm\LlmException;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/**
 * A stage could not complete. Retryable failures are retried with exponential
 * backoff up to the workflow's limits.max_stage_attempts; permanent ones send
 * the order straight to manual review.
 */
final class StageFailure extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly bool $retryable, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function retryable(string $code, string $message, ?Throwable $previous = null): self
    {
        return new self($message, $code, true, $previous);
    }

    public static function permanent(string $code, string $message, ?Throwable $previous = null): self
    {
        return new self($message, $code, false, $previous);
    }

    /** Normalise any exception thrown by a stage. */
    public static function from(Throwable $e): self
    {
        return match (true) {
            $e instanceof self => $e,
            $e instanceof LlmException => new self($e->getMessage(), $e->errorCode, $e->retryable, $e),
            $e instanceof QueryException => new self('Database error: '.mb_substr($e->getMessage(), 0, 300), 'database_error', true, $e),
            default => new self(class_basename($e).': '.mb_substr($e->getMessage(), 0, 500), 'unexpected_error', true, $e),
        };
    }
}
