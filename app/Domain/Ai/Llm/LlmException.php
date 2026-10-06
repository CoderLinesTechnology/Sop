<?php

namespace App\Domain\Ai\Llm;

use RuntimeException;
use Throwable;

/**
 * A failed model call. `retryable` drives the stage retry policy: rate limits,
 * 5xx, timeouts and connection problems are retryable; other 4xx, refusals and
 * output that still violates the schema after one repair are not.
 *
 * Messages are technical and only ever reach logs, ai_jobs.last_error_* and
 * admin notifications, never customers.
 */
final class LlmException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfter = null,
        public readonly ?LlmResponse $response = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function timeout(string $message = 'The model request timed out.', ?Throwable $previous = null): self
    {
        return new self($message, 'timeout', true, previous: $previous);
    }

    public static function connection(string $message, ?Throwable $previous = null): self
    {
        return new self('Could not reach the model provider: '.$message, 'connection_error', true, previous: $previous);
    }

    public static function rateLimited(?int $retryAfter = null, string $message = 'Rate limited by the model provider.'): self
    {
        return new self($message, 'rate_limited', true, 429, $retryAfter);
    }

    public static function server(int $status, string $message): self
    {
        return new self("Model provider error ({$status}): {$message}", 'server_error', true, $status);
    }

    public static function client(int $status, string $message, string $code = 'client_error'): self
    {
        return new self("Model request rejected ({$status}): {$message}", $code, false, $status);
    }

    public static function failed(string $message, ?LlmResponse $response = null): self
    {
        return new self('The model response failed: '.$message, 'response_failed', true, response: $response);
    }

    public static function incomplete(string $reason, LlmResponse $response): self
    {
        // Content-filter stops will not improve on retry; token exhaustion is
        // handled by the gateway (one retry with a larger budget) before this.
        return new self("The model response was incomplete ({$reason}).", 'incomplete', $reason !== 'content_filter', response: $response);
    }

    public static function refusal(string $message, ?LlmResponse $response = null): self
    {
        return new self('The model refused the request: '.$message, 'refusal', false, response: $response);
    }

    public static function invalidOutput(string $message, ?LlmResponse $response = null): self
    {
        return new self('Invalid structured output: '.$message, 'invalid_output', false, response: $response);
    }

    public static function notConfigured(string $message): self
    {
        return new self($message, 'not_configured', false);
    }
}
