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

    /** The webhook signing secret (Paystack signs with the account's secret key). */
    public static function secret(): string
    {
        $secret = (string) config('statementra.paystack.secret_key');

        // Local mock mode without keys: a fixed development-only secret.
        if ($secret === '' && config('statementra.paystack.mode') === 'mock' && ! app()->isProduction()) {
            return 'sk_mock_local_development_only';
        }

        return $secret;
    }

    public static function isValid(string $rawBody, ?string $signature, ?string $secret = null): bool
    {
        $secret ??= self::secret();

        if ($secret === '' || ! is_string($signature) || ! preg_match('/^[a-f0-9]{128}$/i', $signature)) {
            return false;
        }

        return hash_equals(self::compute($rawBody, $secret), strtolower($signature));
    }
}
