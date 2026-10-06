<?php

namespace App\Domain\Orders;

use App\Enums\OrderStatus as S;
use App\Events\OrderStatusChanged;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * The only way an order's status changes.
 *
 * Every transition is validated against the allowed graph and applied with an
 * atomic compare-and-set (UPDATE ... WHERE status = <expected>), so two
 * workers or a webhook racing a redirect can never both win. Each change is
 * recorded in order_status_histories.
 */
final class OrderStateMachine
{
    /** @var array<string, list<S>> */
    private const TRANSITIONS = [
        'NEW' => [S::FormSubmitted, S::Cancelled],
        'FORM_SUBMITTED' => [S::PaymentPending, S::PaymentConfirmed, S::Cancelled],
        'PAYMENT_PENDING' => [S::PaymentConfirmed, S::PaymentFailed, S::PaymentExpired, S::FormSubmitted, S::ManualReview, S::Cancelled],
        'PAYMENT_FAILED' => [S::PaymentPending, S::PaymentConfirmed, S::PaymentExpired, S::FormSubmitted, S::ManualReview, S::Cancelled],
        'PAYMENT_EXPIRED' => [S::PaymentPending, S::PaymentConfirmed, S::FormSubmitted, S::ManualReview, S::Cancelled],
        'PAYMENT_CONFIRMED' => [S::Researching, S::ProcessingFailed, S::ManualReview, S::Cancelled, S::Refunded, S::PartiallyRefunded],
        'RESEARCHING' => [S::ResearchComplete, S::NeedsInformation, S::Writing, S::ProcessingFailed, S::ManualReview, S::Cancelled, S::Refunded],
        'NEEDS_INFORMATION' => [S::Researching, S::ProcessingFailed, S::ManualReview, S::Cancelled, S::Refunded],
        'RESEARCH_COMPLETE' => [S::Writing, S::ProcessingFailed, S::ManualReview, S::Cancelled, S::Refunded],
        'WRITING' => [S::QualityReview, S::ProcessingFailed, S::ManualReview, S::Cancelled, S::Refunded],
        'QUALITY_REVIEW' => [S::Writing, S::FinalReview, S::ProcessingFailed, S::ManualReview, S::Cancelled, S::Refunded],
        'FINAL_REVIEW' => [S::QualityReview, S::Writing, S::DeliveryPending, S::ProcessingFailed, S::ManualReview, S::Cancelled, S::Refunded],
        'DELIVERY_PENDING' => [S::Delivered, S::DeliveryFailed, S::ManualReview],
        'DELIVERY_FAILED' => [S::DeliveryPending, S::Delivered, S::ManualReview, S::Refunded, S::PartiallyRefunded],
        'DELIVERED' => [S::Refunded, S::PartiallyRefunded, S::ManualReview],
        'PROCESSING_FAILED' => [S::ManualReview, S::Researching, S::Writing, S::QualityReview, S::FinalReview, S::DeliveryPending, S::Cancelled, S::Refunded, S::PartiallyRefunded],
        'MANUAL_REVIEW' => [
            S::Researching, S::NeedsInformation, S::Writing, S::QualityReview, S::FinalReview, S::DeliveryPending,
            S::Delivered, S::ProcessingFailed, S::Cancelled, S::Refunded, S::PartiallyRefunded,
        ],
        'CANCELLED' => [S::Refunded, S::PartiallyRefunded],
        'PARTIALLY_REFUNDED' => [S::Refunded, S::ManualReview],
        'REFUNDED' => [],
    ];

    public function canTransition(S $from, S $to): bool
    {
        return $from === $to || in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /** @return list<S> */
    public function allowedFrom(S $from): array
    {
        return self::TRANSITIONS[$from->value] ?? [];
    }

    /**
     * Move an order to a new status.
     *
     * @param  bool  $force  administrator override (super admin, reason required);
     *                       still atomic and recorded, but ignores the graph.
     *
     * @throws InvalidOrderTransition
     */
    public function transition(
        Order $order,
        S $to,
        string $actorType = 'system',
        ?AdminUser $admin = null,
        ?string $reason = null,
        array $meta = [],
        bool $force = false,
    ): Order {
        $from = $order->status;

        if ($from === $to) {
            return $order;
        }

        if (! $force && ! $this->canTransition($from, $to)) {
            throw InvalidOrderTransition::notAllowed($from, $to);
        }

        DB::transaction(function () use ($order, $from, $to, $actorType, $admin, $reason, $meta) {
            $updates = ['status' => $to->value, 'updated_at' => now()] + $this->sideEffects($order, $to);

            $affected = Order::query()
                ->whereKey($order->getKey())
                ->where('status', $from->value)
                ->update($updates);

            if ($affected !== 1) {
                $actual = Order::query()->whereKey($order->getKey())->value('status');
                throw InvalidOrderTransition::stale($from, $actual ? S::from($actual) : null, $to);
            }

            OrderStatusHistory::query()->create([
                'order_id' => $order->getKey(),
                'from_status' => $from,
                'to_status' => $to,
                'actor_type' => $actorType,
                'admin_user_id' => $admin?->getKey(),
                'reason' => $reason ? mb_substr($reason, 0, 255) : null,
                'meta' => $meta ?: null,
                'created_at' => now(),
            ]);

            $order->forceFill($updates);
            $order->syncOriginalAttributes(array_keys($updates));
        });

        event(new OrderStatusChanged($order, $from, $to, $actorType));

        return $order;
    }

    /** Transition if allowed from the current status; returns whether it happened. */
    public function transitionIfAllowed(Order $order, S $to, string $actorType = 'system', ?string $reason = null, array $meta = []): bool
    {
        if ($order->status === $to) {
            return true;
        }
        if (! $this->canTransition($order->status, $to)) {
            return false;
        }

        try {
            $this->transition($order, $to, $actorType, null, $reason, $meta);

            return true;
        } catch (InvalidOrderTransition) {
            $order->refresh();

            return $order->status === $to;
        }
    }

    /** Timestamps that accompany entering a status. */
    private function sideEffects(Order $order, S $to): array
    {
        return match ($to) {
            S::Researching => $order->processing_started_at ? [] : ['processing_started_at' => now()],
            S::NeedsInformation => ['needs_info_at' => now()],
            S::Delivered => ['delivered_at' => $order->delivered_at ?? now()],
            S::Cancelled => ['cancelled_at' => now()],
            default => [],
        };
    }
}
