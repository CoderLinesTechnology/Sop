<?php

namespace App\Domain\Ai\Pipeline;

use App\Models\AiJob;
use App\Models\AiWorkflow;
use App\Models\Order;

/**
 * Captures the effective workflow configuration on each job (so a generation
 * is reproducible even if administrators edit the workflow later) and
 * performs the one-time switch to a workflow's fallback when a budget is
 * exceeded.
 *
 * Snapshot metadata keys (prefixed with "_"):
 *  _workflow       {id, slug, name, version, fallback_workflow_id}
 *  _fallback_from  the _workflow block the job started with (after a switch)
 *  _budget_offset  usage counters at the time of a switch / budget retry
 */
class WorkflowSnapshots
{
    /** The service's workflow at order time (if active), else the default workflow. */
    public function resolveFor(Order $order): ?AiWorkflow
    {
        $id = data_get($order->service_snapshot, 'ai_workflow_id') ?? $order->service?->ai_workflow_id;
        $workflow = $id ? AiWorkflow::query()->where('is_active', true)->find($id) : null;

        return $workflow ?? AiWorkflow::default();
    }

    public function snapshot(?AiWorkflow $workflow): array
    {
        $config = $workflow ? $workflow->effectiveConfig() : AiWorkflow::defaultConfig();

        $config['_workflow'] = [
            'id' => $workflow?->id,
            'slug' => $workflow?->slug,
            'name' => $workflow?->name ?? 'Built-in default',
            'version' => $workflow?->version,
            'fallback_workflow_id' => $workflow?->fallback_workflow_id,
        ];

        return $config;
    }

    public function fallbackFor(AiJob $job): ?AiWorkflow
    {
        $id = $job->config('_workflow.fallback_workflow_id');

        return $id ? AiWorkflow::query()->where('is_active', true)->find($id) : null;
    }

    /** Switch once: the fallback's own fallback is never followed. */
    public function switchToFallback(AiJob $job, AiWorkflow $fallback, array $budgetOffset): void
    {
        $snapshot = $this->snapshot($fallback);
        $snapshot['_workflow']['fallback_workflow_id'] = null;
        $snapshot['_fallback_from'] = $job->config('_workflow');
        $snapshot['_budget_offset'] = $budgetOffset;

        $job->forceFill([
            'workflow_snapshot' => $snapshot,
            'ai_workflow_id' => $fallback->id,
            'used_fallback' => true,
        ])->save();
    }

    /** Give a job a fresh budget allowance (admin retry after a budget stop). */
    public function grantFreshBudget(AiJob $job, array $budgetOffset): void
    {
        $snapshot = (array) $job->workflow_snapshot;
        $snapshot['_budget_offset'] = $budgetOffset;
        $job->forceFill(['workflow_snapshot' => $snapshot])->save();
    }
}
