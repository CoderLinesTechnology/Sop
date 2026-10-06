<?php

namespace App\Domain\Payments;

use App\Models\Payment;
use Illuminate\Support\Str;

/** Unique, unpredictable transaction references (Paystack accepts [A-Za-z0-9.=-]). */
final class PaymentReference
{
    public static function generate(string $prefix = 'STX'): string
    {
        do {
            $reference = $prefix.'-'.Str::upper((string) Str::ulid()).'-'.bin2hex(random_bytes(3));
        } while (Payment::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
