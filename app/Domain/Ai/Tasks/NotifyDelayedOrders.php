<?php

namespace App\Domain\Ai\Tasks;

use App\Domain\Email\OrderEmailVariables;
use App\Domain\Email\TransactionalMailer;
use App\Domain\Notifications\AdminNotifier;
use App\Enums\EmailTemplateKey;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\InformationRequest;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Heartbeat task (every 5 minutes): a paid order still being worked on
 * beyond its service's maximum delivery time plus 10 minutes' grace gets one
 * friendly "still working on it" email (EmailTemplateKey::ProcessingDelay)
 * and admins are alerted (AdminNotifier::aiJobSlow). delay_notified_at is
 * claimed atomically, so nobody is emailed twice. Time spent waiting for the
 * customer's answers is not counted against us.
 */
final class NotifyDelayedOrders
{
    public const INTERVAL_SECONDS = 300;

    public const GRACE_MINUTES = 10;

    private const BATCH = 25;

    /** Statuses in which the customer is still waiting for us. */
    private const WAITING_ON_US = [
        OrderStatus::PaymentConfirmed, OrderStatus::Researching, OrderStatus::ResearchComplete, OrderStatus::Writing,
        OrderStatus::QualityReview, OrderStatus::FinalReview, OrderStatus::DeliveryPending, OrderStatus::ProcessingFailed,
        OrderStatus::ManualReview,
    ];

    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly AdminNotifier $notifier,
    ) {}

    public function __invoke(): void
    {
        $orders = Order::query()
            ->where('payment_status', PaymentStatus::Paid->value)
            ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, self::WAITING_ON_US))
            ->whereNull('delay_notified_at')
            ->whereNotNull('fulfillment_started_at')
            ->where('fulfillment_started_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
            ->orderBy('fulfillment_started_at')
            ->limit(self::BATCH)
            ->get();

        foreach ($orders as $order) {
            $maxMinutes = (int) (data_get($order->service_snapshot, 'delivery_max_minutes') ?: ($order->service?->deliveryWindow()[1] ?? 30));
            $minutes = (int) floor($this->processingSince($order)->diffInMinutes(now(), true));

            if ($minutes < $maxMinutes + self::GRACE_MINUTES) {
                continue;
            }

            $claimed = Order::query()->whereKey($order->id)->whereNull('delay_notified_at')->update(['delay_notified_at' => now()]);
            if ($claimed !== 1) {
                continue;
            }

            $this->mailer->send(EmailTemplateKey::ProcessingDelay, $order->email, OrderEmailVariables::for($order), $order);
            $this->notifier->aiJobSlow($order, $minutes);
        }
    }

    /** When the current wait started: fulfilment, or the customer's latest follow-up answer. */
    private function processingSince(Order $order): CarbonInterface
    {
        $since = $order->fulfillment_started_at;

        $resumed = InformationRequest::query()
            ->where('order_id', $order->id)
            ->whereIn('status', ['answered', 'expired'])
            ->max('updated_at');

        if ($resumed && ($resumedAt = Carbon::parse($resumed))->greaterThan($since)) {
            return $resumedAt;
        }

        return $since;
    }
}
