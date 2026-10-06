<?php

namespace App\Domain\Payments\Tasks;

use App\Domain\Payments\PaymentEventProcessor;
use App\Models\PaymentEvent;

/** Heartbeat task: processes webhook events whose first attempt did not finish or whose retry is due. */
class ProcessPendingPaymentEvents
{
    public function __construct(private readonly PaymentEventProcessor $processor) {}

    public function __invoke(): void
    {
        PaymentEvent::query()
            ->where('processing_status', 'pending')
            ->whereNull('processed_at')
            ->where('signature_valid', true)
            ->where('created_at', '<=', now()->subMinute())
            ->orderBy('id')
            ->limit(25)
            ->get()
            ->filter(fn (PaymentEvent $event) => PaymentEventProcessor::isDue($event))
            ->each(fn (PaymentEvent $event) => $this->processor->process($event->id));
    }
}
