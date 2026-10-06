<?php

namespace App\Domain\Ai\Pipeline;

use App\Enums\PipelineStage;

/**
 * Outcome of a stage handler.
 *
 *  - completed:     checkpoint the output and continue (optionally jumping to $next)
 *  - waiting:       the customer was asked for information; stop until resume()
 *  - manual_review: stop and hand the order to a human (not a technical failure)
 *  - finished:      the pipeline is done (delivery queued)
 */
final class StageResult
{
    public const COMPLETED = 'completed';

    public const WAITING = 'waiting';

    public const MANUAL_REVIEW = 'manual_review';

    public const FINISHED = 'finished';

    private function __construct(
        public readonly string $type,
        public readonly array $output = [],
        public readonly ?PipelineStage $next = null,
        public readonly ?string $reason = null,
        public readonly ?string $code = null,
    ) {}

    public static function completed(array $output, ?PipelineStage $next = null): self
    {
        return new self(self::COMPLETED, $output, $next);
    }

    public static function waitingForCustomer(array $output): self
    {
        return new self(self::WAITING, $output);
    }

    public static function manualReview(string $code, string $reason, array $output = []): self
    {
        return new self(self::MANUAL_REVIEW, $output, reason: $reason, code: $code);
    }

    public static function finished(array $output): self
    {
        return new self(self::FINISHED, $output);
    }
}
