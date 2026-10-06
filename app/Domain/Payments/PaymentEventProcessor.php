<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Paystack\PaystackException;
use App\Models\PaymentEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Processes a stored, signature-verified Paystack webhook. The payload is
 * treated only as a hint: charges are re-verified with the Paystack API
 * before anything changes. Runs right after the webhook is acknowledged;
 * the heartbeat retries events that are still pending.
 */
class PaymentEventProcessor
{
    public const MAX_ATTEMPTS = 6;

    /** Seconds to wait before the next attempt, keyed by attempts made so far. */
    private const BACKOFF = [1 => 15, 2 => 60, 3 => 180, 4 => 600, 5 => 1800];

    public function __construct(
        private readonly PaymentConfirmationService $confirmations,
        private readonly RefundService $refunds,
    ) {}

    public function process(int $eventId): void
    {
        $event = PaymentEvent::query()->find($eventId);
        if (! $event || ! $event->signature_valid || $event->processed_at || $event->processing_status === 'failed') {
            return;
        }

        // One event per payment reference at a time (webhook retries and the heartbeat can overlap).
        $lock = Cache::lock('payment-ref:'.($event->reference ?: 'event-'.$event->id), 120);
        if (! $lock->get()) {
            return;
        }

        try {
            $claimed = PaymentEvent::query()->whereKey($event->id)
                ->whereNull('processed_at')->where('processing_status', 'pending')
                ->update(['attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
            if ($claimed !== 1) {
                return;
            }
            $event->refresh();

            $data = (array) data_get($event->payload, 'data', []);
            $notes = match (true) {
                $event->event_type === 'charge.success' && $event->reference => 'confirmation: '.$this->confirmations->confirmByReference($event->reference, 'webhook')->value,
                str_starts_with($event->event_type, 'refund.') => $this->refund($event->event_type, $data),
                default => 'ignored',
            };

            $event->forceFill([
                'processing_status' => $notes === 'ignored' ? 'ignored' : 'processed',
                'processing_notes' => $notes,
                'processed_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $this->attemptFailed($event, $e);
        } finally {
            $lock->release();
        }
    }

    private function refund(string $type, array $data): string
    {
        $this->refunds->applyProviderUpdate($type, $data);

        return 'refund update applied';
    }

    private function attemptFailed(PaymentEvent $event, Throwable $e): void
    {
        if (! $e instanceof PaystackException) {
            report($e);
        }

        $message = mb_substr($e->getMessage(), 0, 1000);
        if ($event->attempts >= self::MAX_ATTEMPTS) {
            $event->forceFill(['processing_status' => 'failed', 'processing_notes' => $message])->save();

            return;
        }

        $retryAt = now()->addSeconds(self::BACKOFF[$event->attempts] ?? 1800);
        $event->forceFill(['processing_notes' => 'retry after '.$retryAt->toIso8601String().': '.$message])->save();
    }

    /** When the event may be attempted again (attempts are spaced by BACKOFF). */
    public static function isDue(PaymentEvent $event): bool
    {
        if ($event->attempts === 0) {
            return true;
        }

        return $event->updated_at === null
            || $event->updated_at->addSeconds(self::BACKOFF[$event->attempts] ?? 1800)->isPast();
    }
}
