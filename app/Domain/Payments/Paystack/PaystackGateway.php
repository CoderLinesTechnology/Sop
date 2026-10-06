<?php

namespace App\Domain\Payments\Paystack;

/** The subset of the Paystack API Statementra uses. */
interface PaystackGateway
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array{authorization_url:string, access_code:string, reference:string}
     *
     * @throws PaystackException
     */
    public function initialize(string $email, int $amount, string $currency, string $reference, string $callbackUrl, array $metadata = []): array;

    /**
     * Server-to-server verification of a transaction (the only source of truth
     * for whether a customer paid).
     *
     * @return array<string, mixed> the "data" object of GET /transaction/verify/:reference
     *
     * @throws PaystackException
     */
    public function verify(string $reference): array;

    /**
     * @return array<string, mixed> the "data" object of POST /refund
     *
     * @throws PaystackException
     */
    public function refund(string $transactionReference, int $amount, string $currency, string $note): array;
}
