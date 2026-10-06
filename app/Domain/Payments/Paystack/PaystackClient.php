<?php

namespace App\Domain\Payments\Paystack;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Paystack REST client. The secret key is read from server configuration and
 * is only ever sent to api.paystack.co; it never reaches the browser.
 */
final class PaystackClient implements PaystackGateway
{
    public function initialize(string $email, int $amount, string $currency, string $reference, string $callbackUrl, array $metadata = []): array
    {
        $response = $this->send('post', '/transaction/initialize', array_filter([
            'email' => $email,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata ? json_encode($metadata) : null,
            'channels' => config('statementra.paystack.channels') ?: null,
        ]));

        $data = (array) $response->json('data', []);
        if (empty($data['authorization_url']) || empty($data['reference'])) {
            throw new PaystackException('Paystack initialize returned an unexpected payload.', $response->status());
        }

        return [
            'authorization_url' => (string) $data['authorization_url'],
            'access_code' => (string) ($data['access_code'] ?? ''),
            'reference' => (string) $data['reference'],
        ];
    }

    public function verify(string $reference): array
    {
        if (! preg_match('/^[A-Za-z0-9._=-]{6,100}$/', $reference)) {
            throw new PaystackException('Invalid payment reference format.');
        }

        $response = $this->send('get', '/transaction/verify/'.rawurlencode($reference));

        return (array) $response->json('data', []);
    }

    public function refund(string $transactionReference, int $amount, string $currency, string $note): array
    {
        $response = $this->send('post', '/refund', [
            'transaction' => $transactionReference,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'merchant_note' => mb_substr($note, 0, 250),
        ]);

        return (array) $response->json('data', []);
    }

    private function send(string $method, string $path, array $payload = []): Response
    {
        $secret = (string) config('statementra.paystack.secret_key');
        if ($secret === '') {
            throw new PaystackException('PAYSTACK_SECRET_KEY is not configured.');
        }

        try {
            $response = $this->http($secret)->{$method}($path, $payload);
        } catch (ConnectionException $e) {
            throw new PaystackException('Could not reach Paystack: '.$e->getMessage(), null, true);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new PaystackException('Paystack temporarily unavailable (HTTP '.$response->status().').', $response->status(), true);
        }

        if ($response->failed() || $response->json('status') !== true) {
            throw new PaystackException('Paystack error: '.mb_substr((string) $response->json('message', $response->body()), 0, 300), $response->status());
        }

        return $response;
    }

    private function http(string $secret): PendingRequest
    {
        return Http::baseUrl((string) config('statementra.paystack.base_url'))
            ->withToken($secret)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('statementra.paystack.timeout', 20))
            ->connectTimeout(8)
            ->retry(2, 400, fn ($exception) => $exception instanceof ConnectionException, throw: false);
    }
}
