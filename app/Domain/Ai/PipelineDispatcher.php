<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Llm\BudgetGuard;
use App\Domain\Ai\Llm\LlmProviderFactory;
use App\Domain\Ai\Pipeline\PipelineTrigger;
use App\Domain\Ai\Pipeline\RevisionGuard;
use App\Domain\Ai\Pipeline\StagePlan;
use App\Domain\Ai\Pipeline\WorkflowSnapshots;
use App\Domain\Ai\Prompts\PromptRepository;
use App\Domain\Orders\FulfillmentDenied;
use App\Domain\Orders\FulfillmentGuard;
use App\Domain\Orders\InvalidOrderTransition;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\AiJobStatus;
use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Enums\RevisionStatus;
use App\Enums\StepStatus;
use App\Models\AdminUser;
use App\Models\AiJob;
use App\Models\AiJobStep;
use App\Models\Order;
use App\Models\Revision;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Entry point into the AI pipeline used by payments, information requests,
 * revisions, admin actions, the customer's status page and the heartbeat.
 *
 * Request-driven runtime: every method persists the job's state first
 * (status, current_stage, next_run_at) and then kick()s it — the work runs
 * after the current HTTP response (immediately in console/tests), under an
 * atomic lease held by PipelineWorker. Nothing here is queued.
 */
class PipelineDispatcher
{
    public function __construct(
        private readonly FulfillmentGuard $guard,
        private readonly OrderStateMachine $states,
        private readonly WorkflowSnapshots $snapshots,
        private readonly BudgetGuard $budgets,
        private readonly PipelineTrigger $trigger,
    ) {}

    /**
     * Idempotent: one pipeline job per order (dedupe_key "order:{id}:pipeline").
     * Atomically claims fulfilment (FulfillmentGuard::claim verifies the
     * payment) together with creating the job, then kicks it after commit.
     *
     * @throws FulfillmentDenied when the order is not verifiably paid
     */
    public function startForOrder(Order $order): AiJob
    {
        $key = "order:{$order->id}:pipeline";

        if ($existing = $this->findByKey($key)) {
            $this->kick($existing);

            return $existing;
        }

        try {
            // Verified outside the transaction so a denial's audit entry is kept.
            $this->guard->verifiedPayment($order);

            $job = DB::transaction(function () use ($order, $key) {
                $this->guard->claim($order);

                return $this->createJob($order, AiJob::KIND_ORDER, $key);
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->findByKey($key) ?? throw $e;
        } catch (FulfillmentDenied $e) {
            // A concurrent call claimed fulfilment first and created the job.
            if ($e->reason === 'already_started' && ($existing = $this->findByKey($key))) {
                return $existing;
            }

            throw $e;
        }

        $this->kick($job);

        return $job;
    }

    /**
     * Idempotent per revision (dedupe_key "revision:{id}").
     *
     * @throws FulfillmentDenied when the order is no longer paid / the revision is closed or unpaid
     */
    public function startRevision(Revision $revision): AiJob
    {
        $key = "revision:{$revision->id}";

        if ($existing = $this->findByKey($key)) {
            $this->kick($existing);

            return $existing;
        }

        $order = $revision->order()->firstOrFail();
        RevisionGuard::assert($order, $revision);

        if ($revision->status === RevisionStatus::Requested) {
            $revision->forceFill(['status' => RevisionStatus::Processing, 'started_at' => $revision->started_at ?? now()])->save();
        }

        try {
            $job = $this->createJob($order, AiJob::KIND_REVISION, $key, $revision);
        } catch (UniqueConstraintViolationException $e) {
            return $this->findByKey($key) ?? throw $e;
        }

        $this->kick($job);

        return $job;
    }

    /**
     * Continue a job waiting for the customer or paused by an admin.
     *
     * "information_received" re-runs the pipeline from ingestion so the new
     * answers enter the applicant profile (the analysis will not ask again);
     * any other reason (information_expired, an admin's resume) continues
     * after the analysis with the information available. A resume that does
     * not come from an admin never lifts an admin's pause: the job is
     * prepared and continues when the order is unpaused.
     */
    public function resume(Order $order, string $reason = 'resumed', ?AdminUser $admin = null): void
    {
        $order->refresh();
        $liftPause = $order->isPaused() && $admin !== null;
        $staysPaused = $order->isPaused() && ! $liftPause;

        if ($liftPause) {
            Order::query()->whereKey($order->id)->update(['paused_at' => null, 'pause_reason' => null, 'updated_at' => now()]);
            $order->forceFill(['paused_at' => null, 'pause_reason' => null])->syncOriginalAttributes(['paused_at', 'pause_reason']);
        }

        $job = $this->latestJob($order);
        $resumable = $job && in_array($job->status, [AiJobStatus::WaitingForCustomer, AiJobStatus::Paused, AiJobStatus::Queued, AiJobStatus::Running], true);

        if ($resumable) {
            $updates = $staysPaused ? [] : ['next_run_at' => now()];

            if ($job->status === AiJobStatus::WaitingForCustomer) {
                $updates['current_stage'] = ($reason === 'information_received'
                    ? StagePlan::first($job)
                    : (StagePlan::after($job, PipelineStage::Analysis) ?? StagePlan::first($job)))->value;
                $updates['status'] = ($staysPaused ? AiJobStatus::Paused : AiJobStatus::Running)->value;

                if ($order->status === OrderStatus::NeedsInformation) {
                    $this->moveOrder($order, OrderStatus::Researching, $admin, 'Processing resumed ('.$reason.')');
                }
            } elseif ($job->status === AiJobStatus::Paused && ! $staysPaused) {
                $updates['status'] = AiJobStatus::Running->value;
            }

            if ($updates !== []) {
                AiJob::query()->whereKey($job->id)->update($updates);
            }
        }

        if ($admin) {
            Audit::log('ai.pipeline_resumed', $order, meta: ['reason' => $reason, 'job' => $job?->uuid, 'job_status' => $job?->status?->value], admin: $admin);
        }

        if ($resumable && ! $staysPaused) {
            $this->kick($job->refresh());
        }
    }

    /** Pause: the running stage finishes, nothing further starts until resume(). */
    public function pause(Order $order, ?AdminUser $admin, string $reason): void
    {
        $now = now();
        Order::query()->whereKey($order->id)->update(['paused_at' => $now, 'pause_reason' => mb_substr($reason, 0, 255), 'updated_at' => $now]);
        $order->forceFill(['paused_at' => $now, 'pause_reason' => mb_substr($reason, 0, 255)])->syncOriginalAttributes(['paused_at', 'pause_reason']);

        $job = $this->latestJob($order);
        if ($job) {
            AiJob::query()->whereKey($job->id)
                ->whereIn('status', [AiJobStatus::Queued->value, AiJobStatus::Running->value])
                ->update(['status' => AiJobStatus::Paused->value, 'next_run_at' => null]);
        }

        if ($admin) {
            Audit::log('ai.pipeline_paused', $order, meta: ['reason' => $reason, 'job' => $job?->uuid], admin: $admin);
        }
    }

    /**
     * Retry the failed stage of the latest job (resets its attempts): the
     * stage's failed attempts are marked superseded, a job stopped by the
     * quality bar gets fresh refinement rounds, a job stopped by a budget gets
     * a fresh allowance, and the order moves back to the stage's status.
     */
    public function retry(Order $order, AdminUser $admin): void
    {
        $job = $this->latestJob($order);
        if (! $job || ! in_array($job->status, [AiJobStatus::Failed, AiJobStatus::ManualReview], true)) {
            throw new LogicException('Only a failed pipeline run, or one waiting for manual review, can be retried.');
        }

        $stage = $job->current_stage ?? StagePlan::first($job);

        DB::transaction(function () use ($job, $order, $stage, $admin) {
            $sequence = AiJobStep::query()->where('ai_job_id', $job->id)->where('stage', $stage->value)->max('sequence');
            if ($sequence !== null) {
                AiJobStep::query()->where('ai_job_id', $job->id)->where('sequence', $sequence)
                    ->where('status', StepStatus::Failed->value)
                    ->update(['status' => StepStatus::Superseded->value, 'updated_at' => now()]);
            }

            $updates = [
                'status' => AiJobStatus::Running->value,
                'current_stage' => $stage->value,
                'next_run_at' => now(),
                'leased_until' => null,
                'lease_token' => null,
                'finished_at' => null,
                'heartbeat_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ];
            if ($job->last_error_code === 'quality_below_threshold') {
                $updates['refinement_rounds'] = 0;
            }
            if ($job->last_error_code === 'budget_exceeded') {
                $this->snapshots->grantFreshBudget($job, $this->budgets->offsetFor($job));
            }

            AiJob::query()->whereKey($job->id)->update($updates);

            if (! $job->isRevision()) {
                $this->moveOrder($order->refresh(), $stage->orderStatus(), $admin, 'Retrying the "'.$stage->getLabel().'" stage');
            }
        });

        Audit::log('ai.pipeline_retried', $order, meta: ['job' => $job->uuid, 'stage' => $stage->value, 'previous_error' => $job->last_error_code], admin: $admin);

        $this->kick($job->refresh());
    }

    /**
     * Skip the job's current stage (which must have failed or be waiting),
     * recording the reason, and continue with the next one. Rendering, file
     * QA and delivery can never be skipped.
     */
    public function skipStage(Order $order, PipelineStage $stage, AdminUser $admin, string $reason): void
    {
        if (in_array($stage, StagePlan::UNSKIPPABLE, true)) {
            throw new InvalidArgumentException("The \"{$stage->getLabel()}\" stage cannot be skipped.");
        }

        $job = $this->latestJob($order) ?? throw new LogicException('This order has no pipeline run.');

        if (in_array($job->status, [AiJobStatus::Completed, AiJobStatus::Cancelled], true)) {
            throw new LogicException('This pipeline run has already finished.');
        }
        if ($job->current_stage !== $stage) {
            throw new InvalidArgumentException('Only the current stage ('.($job->current_stage?->getLabel() ?? 'none').') can be skipped.');
        }
        if ($job->isLeased()) {
            throw new LogicException('This stage is running right now. Pause the order and try again once it has stopped.');
        }

        $next = StagePlan::after($job, $stage) ?? throw new LogicException('There is no stage after this one.');
        $order->refresh();

        DB::transaction(function () use ($job, $order, $stage, $next, $admin, $reason) {
            $last = AiJobStep::query()->where('ai_job_id', $job->id)->orderByDesc('id')->first();
            $sequence = $last && $last->stage === $stage && $last->status !== StepStatus::Completed
                ? $last->sequence
                : ((int) AiJobStep::query()->where('ai_job_id', $job->id)->max('sequence')) + 1;

            AiJobStep::query()->create([
                'ai_job_id' => $job->id,
                'stage' => $stage,
                'sequence' => $sequence,
                'attempt' => min(255, AiJobStep::query()->where('ai_job_id', $job->id)->where('sequence', $sequence)->count() + 1),
                'status' => StepStatus::Skipped,
                'error_code' => 'skipped_by_admin',
                'error_message' => mb_substr('Skipped by '.$admin->name.': '.$reason, 0, 2000),
                'started_at' => now(),
                'finished_at' => now(),
                'duration_ms' => 0,
            ]);

            $updates = ['current_stage' => $next->value, 'next_run_at' => now(), 'finished_at' => null, 'last_error_code' => null, 'last_error_message' => null];
            if (in_array($job->status, [AiJobStatus::Failed, AiJobStatus::ManualReview, AiJobStatus::WaitingForCustomer], true)) {
                $updates['status'] = ($order->isPaused() ? AiJobStatus::Paused : AiJobStatus::Running)->value;
            }
            AiJob::query()->whereKey($job->id)->update($updates);

            if (! $job->isRevision()) {
                $this->moveOrder($order, $next->orderStatus(), $admin, 'Skipped the "'.$stage->getLabel().'" stage');
            }
        });

        Audit::log('ai.stage_skipped', $order, meta: ['job' => $job->uuid, 'stage' => $stage->value, 'next' => $next->value, 'reason' => $reason], admin: $admin);

        $job->refresh();
        if ($job->status->isRunnable() && ! $order->isPaused()) {
            $this->kick($job);
        }
    }

    /**
     * Stop AI processing for the order: every unfinished job is cancelled.
     *
     * The order itself is never cancelled here — cancelling or refunding an
     * order is the caller's decision (OrderStateMachine / RefundService). If
     * the order was mid-pipeline it moves to MANUAL_REVIEW so a person picks
     * it up instead of it silently stalling.
     */
    public function cancel(Order $order, ?AdminUser $admin, string $reason): void
    {
        $jobs = AiJob::query()
            ->where('order_id', $order->id)
            ->whereNotIn('status', [AiJobStatus::Completed->value, AiJobStatus::Cancelled->value])
            ->get();

        foreach ($jobs as $job) {
            AiJob::query()->whereKey($job->id)->update([
                'status' => AiJobStatus::Cancelled->value,
                'finished_at' => now(),
                'next_run_at' => null,
                'last_error_code' => 'cancelled',
                'last_error_message' => mb_substr($reason, 0, 2000),
            ]);
        }

        $order->refresh();
        $midPipeline = in_array($order->status, [
            OrderStatus::PaymentConfirmed, OrderStatus::Researching, OrderStatus::ResearchComplete, OrderStatus::Writing,
            OrderStatus::QualityReview, OrderStatus::FinalReview, OrderStatus::NeedsInformation, OrderStatus::ProcessingFailed,
        ], true);

        if ($jobs->isNotEmpty() && $midPipeline) {
            $this->moveOrder($order, OrderStatus::ManualReview, $admin, 'AI processing cancelled: '.$reason);
        }

        if ($admin) {
            Audit::log('ai.pipeline_cancelled', $order, meta: ['reason' => $reason, 'jobs' => $jobs->pluck('uuid')->all()], admin: $admin);
        }
    }

    /**
     * Start a completely new generation for the order (kind "regeneration",
     * dedupe "order:{id}:regen:{n}"). Any unfinished run is cancelled first.
     *
     * @throws FulfillmentDenied when the order may no longer be fulfilled
     */
    public function regenerate(Order $order, AdminUser $admin): AiJob
    {
        $this->guard->assertStillFulfillable($order);

        AiJob::query()
            ->where('order_id', $order->id)
            ->whereNotIn('status', [AiJobStatus::Completed->value, AiJobStatus::Cancelled->value])
            ->update([
                'status' => AiJobStatus::Cancelled->value,
                'finished_at' => now(),
                'next_run_at' => null,
                'last_error_code' => 'superseded',
                'last_error_message' => 'Superseded by a regeneration.',
            ]);

        $number = AiJob::query()->where('order_id', $order->id)->where('kind', AiJob::KIND_REGENERATION)->count() + 1;

        for ($tries = 0; ; $tries++) {
            try {
                $job = $this->createJob($order, AiJob::KIND_REGENERATION, "order:{$order->id}:regen:{$number}");
                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($tries >= 5) {
                    throw $e;
                }
                $number++;
            }
        }

        $this->moveOrder($order->refresh(), OrderStatus::Researching, $admin, 'Regenerating the document');

        Audit::log('ai.pipeline_regenerated', $order, meta: ['job' => $job->uuid, 'number' => $number], admin: $admin);

        $this->kick($job);

        return $job;
    }

    // ------------------------------------------------- request-driven runtime

    /** Run the job's due work after the current response (immediately in console/tests). */
    public function kick(AiJob $job): void
    {
        $this->trigger->kick($job);
    }

    /**
     * Cheap check for the customer's status page (polled every few seconds):
     * kick the order's latest job if it is runnable, due and not leased.
     */
    public function kickIfDue(Order $order): bool
    {
        if ($order->paused_at !== null) {
            return false;
        }

        $job = AiJob::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->first(['id', 'uuid', 'order_id', 'status', 'next_run_at', 'leased_until']);

        if (! $job || ! $job->isDue()) {
            return false;
        }

        $this->kick($job);

        return true;
    }

    /**
     * Heartbeat: continue up to $limit due, unleased jobs (oldest first). Each
     * continues in its own fresh request via the signed loopback trigger, so
     * the heartbeat stays short; if the loopback is unavailable, one job is
     * kicked in-process as a fallback.
     *
     * @return int jobs triggered
     */
    public function tickDue(int $limit = 3): int
    {
        $jobs = AiJob::query()
            ->due()
            ->whereHas('order', fn ($q) => $q->whereNull('paused_at'))
            ->orderByRaw('next_run_at IS NOT NULL')
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $kickedInProcess = false;
        $triggered = 0;

        foreach ($jobs as $job) {
            if ($this->trigger->continueElsewhere($job)) {
                $triggered++;
            } elseif (! $kickedInProcess) {
                $kickedInProcess = true;
                $this->kick($job);
                $triggered++;
            }
        }

        return $triggered;
    }

    // ------------------------------------------------------------- internals

    private function createJob(Order $order, string $kind, string $dedupeKey, ?Revision $revision = null): AiJob
    {
        $workflow = $this->snapshots->resolveFor($order);

        $job = new AiJob;
        $job->forceFill([
            'order_id' => $order->id,
            'revision_id' => $revision?->id,
            'kind' => $kind,
            'dedupe_key' => $dedupeKey,
            'status' => AiJobStatus::Queued,
            'ai_workflow_id' => $workflow?->id,
            'workflow_snapshot' => $this->snapshots->snapshot($workflow),
            'prompt_versions' => PromptRepository::activeSnapshot(),
            'provider' => LlmProviderFactory::configuredName(),
            'next_run_at' => now(),
        ]);
        $job->current_stage = StagePlan::first($job);
        $job->save();

        return $job;
    }

    private function findByKey(string $key): ?AiJob
    {
        return AiJob::query()->where('dedupe_key', $key)->first();
    }

    private function latestJob(Order $order): ?AiJob
    {
        return AiJob::query()->where('order_id', $order->id)->orderByDesc('id')->first();
    }

    /** Move the order if the state machine allows it; otherwise leave it as it is. */
    private function moveOrder(Order $order, OrderStatus $to, ?AdminUser $admin, string $reason): void
    {
        if ($order->status === $to || ! $this->states->canTransition($order->status, $to)) {
            return;
        }

        if (! $admin) {
            $this->states->transitionIfAllowed($order, $to, 'system', mb_substr($reason, 0, 255));

            return;
        }

        try {
            $this->states->transition($order, $to, 'admin', $admin, mb_substr($reason, 0, 255));
        } catch (InvalidOrderTransition) {
            $order->refresh(); // changed concurrently; leave it to the newer change
        }
    }
}
