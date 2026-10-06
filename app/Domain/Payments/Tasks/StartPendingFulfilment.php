<?php

namespace App\Domain\Payments\Tasks;

use App\Domain\Ai\PipelineDispatcher;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RevisionStatus;
use App\Models\AiJob;
use App\Models\Order;
use App\Models\Revision;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Heartbeat task and safety net: paid work whose start was missed (the request
 * that confirmed the payment died, or starting failed) is started here.
 * Starting is idempotent (FulfillmentGuard and the AI job dedupe key).
 */
class StartPendingFulfilment
{
    public function __construct(private readonly PipelineDispatcher $dispatcher) {}

    public function __invoke(): void
    {
        Order::query()
            ->where('status', OrderStatus::PaymentConfirmed->value)
            ->where('payment_status', PaymentStatus::Paid->value)
            ->whereNull('paused_at')
            ->whereDoesntHave('aiJobs', fn ($q) => $q->where('kind', AiJob::KIND_ORDER))
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->each(fn (Order $order) => $this->attempt('order '.$order->reference, fn () => $this->dispatcher->startForOrder($order)));

        Revision::query()
            ->where('status', RevisionStatus::Processing->value)
            ->where('mode', 'ai')
            ->where('started_at', '<=', now()->subMinutes(3))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ai_jobs')->whereColumn('ai_jobs.revision_id', 'revisions.id'))
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->each(fn (Revision $revision) => $this->attempt('revision '.$revision->uuid, fn () => $this->dispatcher->startRevision($revision)));
    }

    private function attempt(string $what, \Closure $start): void
    {
        try {
            $start();
        } catch (Throwable $e) {
            Log::warning('Could not start fulfilment yet', ['for' => $what, 'error' => $e->getMessage()]);
        }
    }
}
