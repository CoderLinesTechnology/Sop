<?php

namespace App\Domain\Payments\Paystack;

/**
 * Paystack signs each webhook with HMAC-SHA512 of the raw request body using
 * the account's secret key, sent hex-encoded in X-Paystack-Signature. The raw
 * body must be verified before any JSON parsing.
 */
final class WebhookSignature
{
    public static function compute(string $rawBody, string $secret): string
    {
        return hash_hmac('sha512', $rawBody, $secret);
    }

    public static function isValid(string $rawBody, ?string $signature, ?string $secret = null): bool
    {
        $secret ??= (string) config('statementra.paystack.secret_key');

        if ($secret === '' || ! is_string($signature) || ! preg_match('/^[a-f0-9]{128}$/i', $signature)) {
            return false;
        }

        return hash_equals(self::compute($rawBody, $secret), strtolower($signature));
    }
}
