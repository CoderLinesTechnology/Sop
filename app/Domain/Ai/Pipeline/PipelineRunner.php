<?php

namespace App\Domain\Ai\Pipeline;

use App\Domain\Ai\Llm\BudgetExceeded;
use App\Domain\Ai\Llm\BudgetGuard;
use App\Domain\Ai\Llm\LlmException;
use App\Domain\Notifications\AdminNotifier;
use App\Domain\Orders\FulfillmentDenied;
use App\Domain\Orders\FulfillmentGuard;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\AiJobStatus;
use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Models\AiJob;
use App\Models\AiJobStep;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs exactly one stage of one AI job and decides what happens next. The
 * caller (PipelineWorker) holds the job's lease and persists the returned
 * NextRun (ai_jobs.current_stage / next_run_at).
 *
 * Each execution: checks the job is still runnable and expects this stage
 * (stale or duplicate triggers are no-ops), honours orders.paused_at,
 * re-checks that the order may still be fulfilled, turns an attempt left
 * "running" by a PHP process that died into a failed attempt (retried with
 * backoff, or manual review once attempts are used up), checkpoints the new
 * attempt in ai_job_steps, keeps the order status in step with the stage
 * (PipelineStage::orderStatus()), runs the handler and then:
 *
 *  - completed      → checkpoint output, advance to the next enabled stage
 *  - waiting        → job waiting_for_customer (resume() continues it)
 *  - manual review  → job manual_review, order MANUAL_REVIEW, admins notified
 *  - retryable error → retry with exponential backoff (30 s, 2 min, 8 min…)
 *                     up to limits.max_stage_attempts, then PROCESSING_FAILED
 *                     → MANUAL_REVIEW and AdminNotifier::aiJobFailed
 *  - budget exceeded → one switch to the fallback workflow, or manual review
 *
 * Customers never see technical errors: details go to ai_job_steps,
 * ai_jobs.last_error_* and admin notifications only.
 */
class PipelineRunner
{
    /**
     * Time a single stage may use: well inside the worker's 15-minute lease,
     * and it bounds every model call's HTTP timeout (StageContext::callTimeout).
     */
    public const STAGE_TIME_BUDGET_SECONDS = 600;

    private const BACKOFF_BASE_SECONDS = 30;

    /** Kept below RecoverStalledPipelines' 15-minute threshold. */
    private const BACKOFF_MAX_SECONDS = 600;

    public function __construct(
        private readonly StageRegistry $stages,
        private readonly OrderStateMachine $states,
        private readonly FulfillmentGuard $guard,
        private readonly AdminNotifier $notifier,
        private readonly WorkflowSnapshots $snapshots,
        private readonly BudgetGuard $budgets,
    ) {}

    /** A stage given less than this is not started; the work continues in a fresh request instead. */
    public const MIN_STAGE_SECONDS = 120;

    /**
     * Run one stage. Returns the follow-up run to schedule, or null when the
     * job stops here.
     *
     * $timeBudgetSeconds limits the stage below STAGE_TIME_BUDGET_SECONDS when
     * the caller has less time left; a stage that runs out of such a shortened
     * budget yields (rescheduled immediately, not counted as a failed attempt).
     */
    public function run(int $aiJobId, string $stageValue, ?int $timeBudgetSeconds = null): ?NextRun
    {
        $job = AiJob::query()->find($aiJobId);
        $stage = PipelineStage::tryFrom($stageValue);

        if (! $job || ! $stage || ! $job->status->isRunnable() || $job->current_stage !== $stage) {
            return null; // stale, duplicate or cancelled message
        }

        $order = Order::query()->find($job->order_id);
        if (! $order) {
            return null;
        }

        if ($order->isPaused()) {
            $this->markPaused($job);

            return null;
        }

        try {
            $this->assertFulfillable($job, $order);
        } catch (FulfillmentDenied $e) {
            $this->handleDenied($job, $order, $e);

            return null;
        }

        // An attempt still marked running means the PHP process running it died.
        if ($this->closeInterruptedSteps($job) > 0) {
            return $this->afterInterruption($job, $order, $stage);
        }

        if (! StagePlan::isEnabled($job, $stage)) {
            $this->recordSkipped($job, $stage, 'Disabled in the workflow.');

            return $this->advance($job, $order, StagePlan::after($job, $stage));
        }

        $step = $this->beginStep($job, $stage);
        $this->markRunning($job);
        $this->syncOrderStatus($job, $order, $stage);

        $budget = min(self::STAGE_TIME_BUDGET_SECONDS, max(1, $timeBudgetSeconds ?? self::STAGE_TIME_BUDGET_SECONDS));
        $shortened = $budget < self::STAGE_TIME_BUDGET_SECONDS;
        $ctx = new StageContext($job, $order, $step, $stage, microtime(true) + $budget);

        try {
            $result = $this->stages->for($stage)->run($ctx);
        } catch (BudgetExceeded $e) {
            return $this->onBudgetExceeded($job, $order, $step, $e);
        } catch (FulfillmentDenied $e) {
            $this->closeStep($step, StepStatus::Failed, error: ['fulfillment_denied', $e->getMessage()]);
            $this->handleDenied($job, $order, $e);

            return null;
        } catch (Throwable $e) {
            $failure = StageFailure::from($e);

            // Out of a shortened time budget (the deadline, not the provider, ended the attempt):
            // continue with a full budget in a fresh request instead of counting a failed attempt.
            $outOfTime = $shortened && $ctx->remainingSeconds() < 70
                && in_array($failure->errorCode, ['stage_time_exhausted', 'timeout'], true);
            if ($outOfTime) {
                $this->closeStep($step, StepStatus::Superseded, error: [$failure->errorCode, 'Ran out of the time left in this request; continuing in a fresh one.']);

                return $this->stillRunnable($job, $order) ? new NextRun($stage) : null;
            }

            return $this->onError($job, $order, $step, $failure);
        }

        return $this->onResult($job, $order, $step, $result);
    }

    /**
     * Recovery for an attempt left "running" after its worker's lease expired
     * (the PHP process was killed). The attempt counts as failed: it is
     * retried with backoff, or the order goes to manual review once
     * limits.max_stage_attempts is used up.
     *
     * @return array{0:bool, 1:?NextRun} [whether anything was recovered, follow-up]
     */
    public function recoverInterrupted(int $aiJobId): array
    {
        $job = AiJob::query()->find($aiJobId);
        if (! $job || ! $job->status->isRunnable() || ! $job->current_stage) {
            return [false, null];
        }

        $order = Order::query()->find($job->order_id);
        if (! $order || $this->closeInterruptedSteps($job) === 0) {
            return [false, null];
        }

        return [true, $this->afterInterruption($job, $order, $job->current_stage)];
    }

    // ------------------------------------------------------------- outcomes

    private function onResult(AiJob $job, Order $order, AiJobStep $step, StageResult $result): ?NextRun
    {
        switch ($result->type) {
            case StageResult::WAITING:
                $this->closeStep($step, StepStatus::Completed, $result->output);
                AiJob::query()->whereKey($job->id)
                    ->whereIn('status', [AiJobStatus::Queued->value, AiJobStatus::Running->value])
                    ->update(['status' => AiJobStatus::WaitingForCustomer->value, 'heartbeat_at' => now()]);

                return null;

            case StageResult::MANUAL_REVIEW:
                $this->closeStep($step, StepStatus::Failed, $result->output, [$result->code ?? 'manual_review', (string) $result->reason]);
                $this->toManualReview($job, $order, $result->code ?? 'manual_review', (string) $result->reason);

                return null;

            case StageResult::FINISHED:
                $this->closeStep($step, StepStatus::Completed, $result->output);
                $this->completeJob($job);

                return null;

            default:
                $this->closeStep($step, StepStatus::Completed, $result->output);

                return $this->advance($job, $order, $result->next ?? StagePlan::after($job, $step->stage));
        }
    }

    private function onError(AiJob $job, Order $order, AiJobStep $step, StageFailure $failure): ?NextRun
    {
        $this->closeStep($step, StepStatus::Failed, error: [$failure->errorCode, $failure->getMessage()]);

        AiJob::query()->whereKey($job->id)->update([
            'failure_count' => DB::raw('failure_count + 1'),
            'last_error_code' => mb_substr($failure->errorCode, 0, 60),
            'last_error_message' => mb_substr($failure->getMessage(), 0, 2000),
            'heartbeat_at' => now(),
        ]);

        $context = ['job' => $job->uuid, 'order' => $order->reference, 'stage' => $step->stage->value, 'attempt' => $step->attempt, 'code' => $failure->errorCode];
        if ($failure->errorCode === 'unexpected_error' && $failure->getPrevious()) {
            report($failure->getPrevious());
        }
        Log::warning('AI stage failed: '.$failure->getMessage(), $context);

        $attempts = $this->failedAttempts($job, $step->stage, $step->sequence);
        $max = max(1, (int) $job->config('limits.max_stage_attempts', 3));

        if ($failure->retryable && $attempts < $max) {
            if (! $this->stillRunnable($job, $order)) {
                return null;
            }

            return new NextRun($step->stage, $this->backoff($attempts, $failure));
        }

        $this->failPipeline($job, $order, $step->stage, $failure, $attempts);

        return null;
    }

    private function onBudgetExceeded(AiJob $job, Order $order, AiJobStep $step, BudgetExceeded $e): ?NextRun
    {
        $job->refresh();
        $canFallBack = ! $e->isGlobal()
            && $job->config('on_budget_exceeded', 'fallback') === 'fallback'
            && ! $job->used_fallback
            && ($fallback = $this->snapshots->fallbackFor($job)) !== null;

        if ($canFallBack) {
            $this->closeStep($step, StepStatus::Superseded, error: ['budget_exceeded', $e->getMessage().' Switching to the fallback workflow.']);
            $this->snapshots->switchToFallback($job, $fallback, $this->budgets->offsetFor($job));

            Log::warning('AI job switched to its fallback workflow after a budget limit.', [
                'job' => $job->uuid, 'order' => $order->reference, 'limit' => $e->limit, 'fallback' => $fallback->slug,
            ]);

            return $this->stillRunnable($job, $order) ? new NextRun($step->stage) : null;
        }

        $this->closeStep($step, StepStatus::Failed, error: ['budget_exceeded', $e->getMessage()]);
        $this->toManualReview($job, $order, 'budget_exceeded', 'AI budget limit reached: '.$e->getMessage());

        return null;
    }

    private function handleDenied(AiJob $job, Order $order, FulfillmentDenied $e): void
    {
        if (in_array($e->reason, ['order_closed', 'revision_closed'], true)) {
            AiJob::query()->whereKey($job->id)->update([
                'status' => AiJobStatus::Cancelled->value,
                'finished_at' => now(),
                'last_error_code' => $e->reason,
                'last_error_message' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            return;
        }

        $this->toManualReview($job, $order, 'fulfillment_denied', 'AI processing stopped: the payment could not be verified for fulfilment ('.$e->reason.').');
    }

    private function failPipeline(AiJob $job, Order $order, PipelineStage $stage, StageFailure $failure, int $attempts): void
    {
        AiJob::query()->whereKey($job->id)->where('status', '!=', AiJobStatus::Cancelled->value)->update([
            'status' => AiJobStatus::Failed->value,
            'finished_at' => now(),
            'last_error_code' => mb_substr($failure->errorCode, 0, 60),
            'last_error_message' => mb_substr($failure->getMessage(), 0, 2000),
        ]);

        if ($job->kind !== AiJob::KIND_REVISION) {
            $order->refresh();
            $this->states->transitionIfAllowed($order, OrderStatus::ProcessingFailed, 'system', "AI stage {$stage->value} failed ({$failure->errorCode})");
            $this->states->transitionIfAllowed($order, OrderStatus::ManualReview, 'system', 'Handed to the team after an AI processing failure');
        }

        $what = $job->kind === AiJob::KIND_REVISION ? 'Revision processing' : 'AI processing';
        $this->notifier->aiJobFailed($order, sprintf(
            '%s stopped at "%s" after %d attempt(s) [%s]: %s',
            $what, $stage->getLabel(), $attempts, $failure->errorCode, mb_substr($failure->getMessage(), 0, 500),
        ));
    }

    /** A non-technical stop (quality, limits, file QA, budget...): a person takes over. */
    private function toManualReview(AiJob $job, Order $order, string $code, string $reason): void
    {
        AiJob::query()->whereKey($job->id)->where('status', '!=', AiJobStatus::Cancelled->value)->update([
            'status' => AiJobStatus::ManualReview->value,
            'finished_at' => now(),
            'last_error_code' => mb_substr($code, 0, 60),
            'last_error_message' => mb_substr($reason, 0, 2000),
        ]);

        if ($job->kind !== AiJob::KIND_REVISION) {
            $order->refresh();
            $this->states->transitionIfAllowed($order, OrderStatus::ManualReview, 'system', mb_substr($reason, 0, 200));
        }

        $this->notifier->manualReviewRequired($order, ($job->kind === AiJob::KIND_REVISION ? 'Revision: ' : '').$reason);
    }

    private function completeJob(AiJob $job): void
    {
        AiJob::query()->whereKey($job->id)
            ->whereIn('status', [AiJobStatus::Queued->value, AiJobStatus::Running->value])
            ->update(['status' => AiJobStatus::Completed->value, 'finished_at' => now(), 'heartbeat_at' => now()]);
    }

    // ------------------------------------------------------------ mechanics

    private function advance(AiJob $job, Order $order, ?PipelineStage $next): ?NextRun
    {
        while ($next !== null && ! StagePlan::isEnabled($job, $next)) {
            $this->recordSkipped($job, $next, 'Disabled in the workflow.');
            $next = StagePlan::after($job, $next);
        }

        if ($next === null) {
            $this->completeJob($job);

            return null;
        }

        AiJob::query()->whereKey($job->id)->update(['current_stage' => $next->value, 'heartbeat_at' => now()]);
        $job->forceFill(['current_stage' => $next])->syncOriginalAttribute('current_stage');

        return $this->stillRunnable($job, $order) ? new NextRun($next) : null;
    }

    /** Re-read state changed concurrently by admins (pause / cancel) before scheduling more work. */
    private function stillRunnable(AiJob $job, Order $order): bool
    {
        $status = AiJobStatus::tryFrom((string) AiJob::query()->whereKey($job->id)->toBase()->value('status'));
        if (! $status?->isRunnable()) {
            return false;
        }

        if (Order::query()->whereKey($order->id)->whereNotNull('paused_at')->exists()) {
            $this->markPaused($job);

            return false;
        }

        return true;
    }

    private function assertFulfillable(AiJob $job, Order $order): void
    {
        if ($job->kind === AiJob::KIND_REVISION) {
            RevisionGuard::assert($order, $job->revision()->first());

            return;
        }

        $this->guard->assertStillFulfillable($order);
    }

    /** Close attempts whose PHP process died mid-stage as failed. Returns how many. */
    private function closeInterruptedSteps(AiJob $job): int
    {
        $steps = AiJobStep::query()->where('ai_job_id', $job->id)->where('status', StepStatus::Running->value)->get();

        foreach ($steps as $step) {
            $this->closeStep($step, StepStatus::Failed, error: ['interrupted', 'The process running this attempt stopped before it finished (lease expired).']);
        }

        if ($steps->isNotEmpty()) {
            AiJob::query()->whereKey($job->id)->update([
                'failure_count' => DB::raw('failure_count + '.$steps->count()),
                'last_error_code' => 'interrupted',
                'last_error_message' => 'A stage attempt was interrupted before it finished.',
            ]);
        }

        return $steps->count();
    }

    /** Retry an interrupted stage with backoff, or stop once its attempts are used up. */
    private function afterInterruption(AiJob $job, Order $order, PipelineStage $stage): ?NextRun
    {
        $attempts = $this->failedAttempts($job, $stage);
        $max = max(1, (int) $job->config('limits.max_stage_attempts', 3));

        Log::warning('AI stage attempt was interrupted.', ['job' => $job->uuid, 'order' => $order->reference, 'stage' => $stage->value, 'failed_attempts' => $attempts]);

        if ($attempts >= $max) {
            $this->failPipeline($job, $order, $stage, StageFailure::permanent('interrupted', 'The stage was interrupted repeatedly (the PHP process stopped before it finished).'), $attempts);

            return null;
        }

        return $this->stillRunnable($job, $order)
            ? new NextRun($stage, $this->backoff($attempts, StageFailure::retryable('interrupted', 'Interrupted.')))
            : null;
    }

    private function beginStep(AiJob $job, PipelineStage $stage): AiJobStep
    {
        $last = AiJobStep::query()->where('ai_job_id', $job->id)->orderByDesc('id')->first();
        $continues = $last && $last->stage === $stage && in_array($last->status, [StepStatus::Failed, StepStatus::Superseded], true);
        $sequence = $continues ? $last->sequence : ((int) AiJobStep::query()->where('ai_job_id', $job->id)->max('sequence')) + 1;
        $attempt = AiJobStep::query()->where('ai_job_id', $job->id)->where('sequence', $sequence)->count() + 1;

        return AiJobStep::query()->create([
            'ai_job_id' => $job->id,
            'stage' => $stage,
            'sequence' => $sequence,
            'attempt' => min(255, $attempt),
            'status' => StepStatus::Running,
            'started_at' => now(),
            'input_summary' => [
                'kind' => $job->kind,
                'workflow' => $job->config('_workflow.slug'),
                'workflow_version' => $job->config('_workflow.version'),
                'fallback' => (bool) $job->used_fallback,
                'provider' => $job->provider,
                'configured_model' => $job->config("stages.{$stage->value}.model"),
                'prompt_key' => $job->config("stages.{$stage->value}.prompt_key", $stage->value),
            ],
        ]);
    }

    private function recordSkipped(AiJob $job, PipelineStage $stage, string $reason): void
    {
        $sequence = ((int) AiJobStep::query()->where('ai_job_id', $job->id)->max('sequence')) + 1;

        AiJobStep::query()->create([
            'ai_job_id' => $job->id,
            'stage' => $stage,
            'sequence' => $sequence,
            'attempt' => 1,
            'status' => StepStatus::Skipped,
            'error_message' => $reason,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 0,
        ]);
    }

    /** @param  array{0:string,1:string}|null  $error */
    private function closeStep(AiJobStep $step, StepStatus $status, ?array $output = null, ?array $error = null): void
    {
        $finished = now();

        $step->forceFill([
            'status' => $status,
            'output' => $output ?? $step->output,
            'error_code' => isset($error[0]) ? mb_substr($error[0], 0, 60) : null,
            'error_message' => isset($error[1]) ? mb_substr($error[1], 0, 2000) : null,
            'finished_at' => $finished,
            'duration_ms' => $step->started_at ? (int) abs($step->started_at->diffInMilliseconds($finished)) : null,
        ])->save();
    }

    private function markRunning(AiJob $job): void
    {
        $updates = ['status' => AiJobStatus::Running->value, 'heartbeat_at' => now()];
        if (! $job->started_at) {
            $updates['started_at'] = now();
        }

        AiJob::query()->whereKey($job->id)
            ->whereIn('status', [AiJobStatus::Queued->value, AiJobStatus::Running->value])
            ->update($updates);

        $job->refresh();
    }

    private function markPaused(AiJob $job): void
    {
        AiJob::query()->whereKey($job->id)
            ->whereIn('status', [AiJobStatus::Queued->value, AiJobStatus::Running->value])
            ->update(['status' => AiJobStatus::Paused->value, 'heartbeat_at' => now()]);
    }

    /** Keep the order status in step with the stage, never moving it backwards needlessly. */
    private function syncOrderStatus(AiJob $job, Order $order, PipelineStage $stage): void
    {
        if ($job->kind === AiJob::KIND_REVISION) {
            return; // the order stays DELIVERED while a revision is prepared
        }

        $target = $stage->orderStatus();
        if ($order->status === $target || ($target === OrderStatus::Researching && $order->status === OrderStatus::ResearchComplete)) {
            return;
        }

        $this->states->transitionIfAllowed($order, $target, 'system', 'AI pipeline: '.$stage->getLabel());
    }

    private function failedAttempts(AiJob $job, PipelineStage $stage, ?int $sequence = null): int
    {
        $sequence ??= AiJobStep::query()->where('ai_job_id', $job->id)->where('stage', $stage->value)->max('sequence');

        return AiJobStep::query()
            ->where('ai_job_id', $job->id)
            ->where('sequence', $sequence)
            ->where('status', StepStatus::Failed->value)
            ->count();
    }

    private function backoff(int $attempts, StageFailure $failure): int
    {
        $delay = self::BACKOFF_BASE_SECONDS * (4 ** max(0, $attempts - 1));

        $previous = $failure->getPrevious();
        if ($previous instanceof LlmException && $previous->retryAfter) {
            $delay = max($delay, $previous->retryAfter);
        }

        return (int) min(self::BACKOFF_MAX_SECONDS, $delay);
    }
}
