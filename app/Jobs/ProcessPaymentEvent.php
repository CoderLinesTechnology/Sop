<?php

namespace App\Jobs;

use App\Domain\Payments\PaymentConfirmationService;
use App\Domain\Payments\Paystack\PaystackException;
use App\Domain\Payments\RefundService;
use App\Models\PaymentEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Processes a stored, signature-verified Paystack webhook. The payload is
 * treated only as a hint: charges are re-verified with the Paystack API
 * before anything changes.
 */
class ProcessPaymentEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 60;

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue('payments');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180, 600, 1800];
    }

    public function middleware(): array
    {
        $reference = PaymentEvent::query()->whereKey($this->eventId)->value('reference') ?? 'none';

        return [(new WithoutOverlapping('payment-ref:'.$reference))->releaseAfter(10)->expireAfter(120)];
    }

    public function handle(PaymentConfirmationService $confirmations, RefundService $refunds): void
    {
        $event = PaymentEvent::query()->find($this->eventId);
        if (! $event || ! $event->signature_valid || $event->processed_at) {
            return;
        }

        $data = (array) data_get($event->payload, 'data', []);

        try {
            $notes = match (true) {
                $event->event_type === 'charge.success' && $event->reference => 'confirmation: '.$confirmations->confirmByReference($event->reference, 'webhook')->value,
                str_starts_with($event->event_type, 'refund.') => $this->refund($refunds, $event->event_type, $data),
                default => 'ignored',
            };
        } catch (PaystackException $e) {
            $event->forceFill(['processing_notes' => 'retrying: '.$e->getMessage()])->save();
            throw $e;
        }

        $event->forceFill([
            'processing_status' => $notes === 'ignored' ? 'ignored' : 'processed',
            'processing_notes' => $notes,
            'processed_at' => now(),
        ])->save();
    }

    private function refund(RefundService $refunds, string $type, array $data): string
    {
        $refunds->applyProviderUpdate($type, $data);

        return 'refund update applied';
    }

    public function failed(\Throwable $e): void
    {
        PaymentEvent::query()->whereKey($this->eventId)->update([
            'processing_status' => 'failed',
            'processing_notes' => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }
}
