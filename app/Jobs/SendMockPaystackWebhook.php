<?php

namespace App\Jobs;

use App\Domain\Payments\Paystack\WebhookSignature;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Development only: delivers a correctly signed webhook to this application,
 * exactly as Paystack would, after a simulated payment in mock mode.
 */
class SendMockPaystackWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly array $payload)
    {
        $this->onQueue('payments');
    }

    public function handle(): void
    {
        if (app()->isProduction() || config('statementra.paystack.mode') !== 'mock') {
            return;
        }

        $body = json_encode($this->payload, JSON_UNESCAPED_SLASHES);

        Http::withHeaders([
            'X-Paystack-Signature' => WebhookSignature::compute($body, (string) config('statementra.paystack.secret_key')),
            'Content-Type' => 'application/json',
        ])->withBody($body, 'application/json')->post(route('webhooks.paystack'));
    }
}
