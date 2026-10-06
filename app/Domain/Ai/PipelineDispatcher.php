<?php

namespace App\Domain\Ai;

use App\Enums\PipelineStage;
use App\Models\AdminUser;
use App\Models\AiJob;
use App\Models\Order;
use App\Models\Revision;
use LogicException;

/**
 * Entry point into the AI pipeline used by payments, information requests,
 * revisions and admin actions. (Implementation owned by the AI pipeline.)
 */
class PipelineDispatcher
{
    /** Idempotent: one pipeline job per order (dedupe_key "order:{id}:pipeline"); dispatched after commit. */
    public function startForOrder(Order $order): AiJob
    {
        throw new LogicException('Not implemented yet.');
    }

    /** Idempotent per revision (dedupe_key "revision:{id}"). */
    public function startRevision(Revision $revision): AiJob
    {
        throw new LogicException('Not implemented yet.');
    }

    /** Continue a job waiting for the customer or paused by an admin. */
    public function resume(Order $order, string $reason = 'resumed', ?AdminUser $admin = null): void
    {
        throw new LogicException('Not implemented yet.');
    }

    public function pause(Order $order, ?AdminUser $admin, string $reason): void
    {
        throw new LogicException('Not implemented yet.');
    }

    /** Retry the failed stage of the latest job (resets its attempts). */
    public function retry(Order $order, AdminUser $admin): void
    {
        throw new LogicException('Not implemented yet.');
    }

    public function skipStage(Order $order, PipelineStage $stage, AdminUser $admin, string $reason): void
    {
        throw new LogicException('Not implemented yet.');
    }

    public function cancel(Order $order, ?AdminUser $admin, string $reason): void
    {
        throw new LogicException('Not implemented yet.');
    }

    /** Start a completely new generation for the order (kind "regeneration"). */
    public function regenerate(Order $order, AdminUser $admin): AiJob
    {
        throw new LogicException('Not implemented yet.');
    }
}
