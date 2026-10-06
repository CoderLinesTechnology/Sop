<?php

namespace App\Filament\Resources\Orders;

use App\Domain\Ai\Pipeline\StagePlan;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\AiJobStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\PipelineStage;
use App\Enums\RefundStatus;
use App\Filament\Support\Operations\Format;
use App\Filament\Support\Operations\RecordMemo;
use App\Models\AiJob;
use App\Models\DocumentVersion;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Collection;

/**
 * Facts about an order that drive which admin actions are offered. Reads the
 * record's (lazily loaded, per-request) relations so visibility checks on
 * many actions do not repeat queries.
 */
final class OrderInsights
{
    public static function latestJob(Order $order): ?AiJob
    {
        return $order->aiJobs->sortByDesc('id')->first();
    }

    public static function hasActiveJob(Order $order): bool
    {
        $job = self::latestJob($order);

        return $job !== null && ! in_array($job->status, [AiJobStatus::Completed, AiJobStatus::Cancelled], true);
    }

    /** PipelineDispatcher::retry() only retries a failed run or one waiting for manual review. */
    public static function canRetry(Order $order): bool
    {
        $job = self::latestJob($order);

        return $job !== null && in_array($job->status, [AiJobStatus::Failed, AiJobStatus::ManualReview], true);
    }

    public static function canPause(Order $order): bool
    {
        $job = self::latestJob($order);

        return ! $order->isPaused()
            && ! $order->status->isTerminal()
            && $job !== null
            && in_array($job->status, [AiJobStatus::Queued, AiJobStatus::Running, AiJobStatus::WaitingForCustomer], true);
    }

    public static function canResume(Order $order): bool
    {
        $job = self::latestJob($order);

        return ! $order->status->isTerminal()
            && ($order->isPaused() || ($job !== null && in_array($job->status, [AiJobStatus::Paused, AiJobStatus::WaitingForCustomer], true)));
    }

    /**
     * The stage "Skip failed step" may skip. PipelineDispatcher::skipStage()
     * only skips the current stage of the latest run, never rendering, file
     * QA or delivery, and not while a worker holds the run; the action is
     * offered for runs that stopped (failed or handed to manual review).
     *
     * @return array<string, string> stage value => label (at most one entry)
     */
    public static function skippableStages(Order $order): array
    {
        $job = self::latestJob($order);
        $stage = $job?->current_stage;

        if (! $job
            || ! $stage instanceof PipelineStage
            || ! in_array($job->status, [AiJobStatus::Failed, AiJobStatus::ManualReview], true)
            || in_array($stage, StagePlan::UNSKIPPABLE, true)
            || StagePlan::after($job, $stage) === null
            || $job->isLeased()) {
            return [];
        }

        return [$stage->value => $stage->getLabel().($stage->isRequired() ? ' (required stage)' : '')];
    }

    public static function canRegenerate(Order $order): bool
    {
        return in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)
            && ! in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)
            && $order->aiJobs->isNotEmpty();
    }

    public static function canStartProcessing(Order $order): bool
    {
        return $order->isPaid() && $order->aiJobs->isEmpty() && ! $order->status->isTerminal();
    }

    /** Whether the order has reached payment (drafts have no customer link or documents). */
    public static function isSubmitted(Order $order): bool
    {
        return ! in_array($order->status, [OrderStatus::New, OrderStatus::FormSubmitted], true);
    }

    /** @return list<OrderStatus> */
    public static function allowedTransitions(Order $order): array
    {
        return app(OrderStateMachine::class)->allowedFrom($order->status);
    }

    /**
     * The captured order payment refunds are taken from (as RefundService
     * selects it). Memoised for the request; the order page forgets it after
     * every action.
     */
    public static function capturedPayment(Order $order): ?Payment
    {
        return RecordMemo::remember($order, 'captured-payment', fn (): ?Payment => $order->payments()
            ->where('purpose', 'order')
            ->whereIn('status', [PaymentRecordStatus::Success->value, PaymentRecordStatus::PartiallyRefunded->value])
            ->latest('id')
            ->first());
    }

    public static function refundableAmount(Order $order): int
    {
        return RecordMemo::remember($order, 'refundable-amount', fn (): int => self::capturedPayment($order)?->refundableAmount() ?? 0);
    }

    public static function hasOpenRefund(Order $order): bool
    {
        return $order->refunds->contains(
            fn (Refund $refund) => in_array($refund->status, [RefundStatus::Requested, RefundStatus::Approved, RefundStatus::Processing], true),
        );
    }

    /** @return Collection<int, DocumentVersion> newest first */
    public static function versions(Order $order): Collection
    {
        return $order->documentVersions->sortByDesc('version_number')->values();
    }

    /** @return Collection<int, DocumentVersion> */
    public static function deliverableVersions(Order $order): Collection
    {
        return self::versions($order)->filter(fn (DocumentVersion $version) => $version->isDeliverable())->values();
    }

    /** @return array<int, string> version id => description */
    public static function versionOptions(Collection $versions): array
    {
        return $versions->mapWithKeys(fn (DocumentVersion $version) => [
            $version->id => 'v'.$version->version_number
                .' · '.str((string) $version->source)->replace('_', ' ')->ucfirst()
                .' · QA '.($version->qa_status ?: 'not checked')
                .' · '.Format::dateTime($version->created_at),
        ])->all();
    }
}
