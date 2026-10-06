<?php

namespace App\Domain\Payments\Paystack;

use App\Http\Controllers\Webhooks\PaystackWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Local simulation of Paystack for development and end-to-end tests
 * (PAYSTACK_MODE=mock). It mirrors the real payloads closely enough to
 * exercise the full verify/webhook path. Refused in production.
 */
final class MockPaystackGateway implements PaystackGateway
{
    private const TTL_HOURS = 48;

    public function __construct()
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The mock Paystack gateway cannot be used in production.');
        }
    }

    public function initialize(string $email, int $amount, string $currency, string $reference, string $callbackUrl, array $metadata = []): array
    {
        Cache::put($this->key($reference), [
            'id' => random_int(100000000, 999999999),
            'reference' => $reference,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'status' => 'ongoing',
            'customer' => ['email' => $email],
            'callback_url' => $callbackUrl,
            'paid_at' => null,
            'channel' => null,
            'gateway_response' => 'Pending',
        ], now()->addHours(self::TTL_HOURS));

        return [
            'authorization_url' => URL::temporarySignedRoute('dev.paystack.checkout', now()->addHours(2), ['reference' => $reference]),
            'access_code' => 'mock_'.bin2hex(random_bytes(6)),
            'reference' => $reference,
        ];
    }

    public function verify(string $reference): array
    {
        $transaction = Cache::get($this->key($reference));
        if (! $transaction) {
            throw new PaystackException('Transaction reference not found', 400);
        }

        return $transaction;
    }

    public function refund(string $transactionReference, int $amount, string $currency, string $note): array
    {
        $transaction = $this->verify($transactionReference);

        return [
            'id' => random_int(1000000, 9999999),
            'transaction' => ['reference' => $transactionReference, 'id' => $transaction['id']],
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'status' => 'processed',
        ];
    }

    /** Called by the mock checkout page to simulate the customer's choice. */
    public function complete(string $reference, bool $success): array
    {
        $transaction = $this->verify($reference);
        $transaction['status'] = $success ? 'success' : 'failed';
        $transaction['gateway_response'] = $success ? 'Successful' : 'Declined';
        $transaction['channel'] = 'card';
        $transaction['paid_at'] = $success ? now()->toIso8601String() : null;
        $transaction['authorization'] = ['last4' => '4081', 'card_type' => 'visa', 'bank' => 'TEST BANK'];

        Cache::put($this->key($reference), $transaction, now()->addHours(self::TTL_HOURS));

        return $transaction;
    }

    /**
     * Deliver a correctly signed webhook exactly as Paystack would. It is handed
     * to the webhook controller in-process: the single-threaded development
     * server cannot answer an HTTP request to itself while busy with this one.
     */
    public function deliverWebhook(string $event, array $data): void
    {
        $body = (string) json_encode(['event' => $event, 'data' => $data], JSON_UNESCAPED_SLASHES);
        $request = Request::create('/webhooks/paystack', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => WebhookSignature::compute($body, WebhookSignature::secret()),
            'REMOTE_ADDR' => '127.0.0.1',
        ], content: $body);

        app()->call([app(PaystackWebhookController::class), '__invoke'], ['request' => $request]);
    }

    private function key(string $reference): string
    {
        return 'mock_paystack:'.$reference;
    }
}
