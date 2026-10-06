<?php

namespace App\Filament\Resources\Orders;

use App\Domain\Orders\OrderStateMachine;
use App\Enums\AiJobStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\PipelineStage;
use App\Enums\RefundStatus;
use App\Enums\StepStatus;
use App\Filament\Support\Operations\Format;
use App\Models\AiJob;
use App\Models\AiJobStep;
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

    public static function canRetry(Order $order): bool
    {
        $job = self::latestJob($order);

        return $job !== null
            && (in_array($job->status, [AiJobStatus::Failed, AiJobStatus::ManualReview], true)
                || in_array($order->status, [OrderStatus::ProcessingFailed, OrderStatus::ManualReview], true));
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
     * Stages of the latest run whose most recent attempt failed.
     *
     * @return array<string, string> stage value => label
     */
    public static function failedStages(Order $order): array
    {
        $job = self::latestJob($order);
        if (! $job || ! in_array($job->status, [AiJobStatus::Failed, AiJobStatus::ManualReview, AiJobStatus::Paused], true)) {
            return [];
        }

        return $job->steps
            ->groupBy(fn (AiJobStep $step) => $step->stage?->value)
            ->map(fn (Collection $attempts) => $attempts->sortBy('id')->last())
            ->filter(fn (AiJobStep $step) => $step->status === StepStatus::Failed && $step->stage instanceof PipelineStage)
            ->sortBy(fn (AiJobStep $step) => $step->stage->position())
            ->mapWithKeys(fn (AiJobStep $step) => [
                $step->stage->value => $step->stage->getLabel().($step->stage->isRequired() ? ' (required stage)' : ''),
            ])
            ->all();
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

    /** The captured order payment refunds are taken from (as RefundService selects it). */
    public static function capturedPayment(Order $order): ?Payment
    {
        return $order->payments()
            ->where('purpose', 'order')
            ->whereIn('status', [PaymentRecordStatus::Success->value, PaymentRecordStatus::PartiallyRefunded->value])
            ->latest('id')
            ->first();
    }

    public static function refundableAmount(Order $order): int
    {
        return self::capturedPayment($order)?->refundableAmount() ?? 0;
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
