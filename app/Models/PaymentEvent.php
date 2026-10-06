<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A raw webhook delivery from the payment provider, kept for idempotency
 * (unique payload hash) and forensics, including rejected (bad signature)
 * deliveries.
 */
#[Fillable(['provider', 'event_type', 'reference', 'provider_event_id', 'payload_hash', 'signature_valid', 'source_ip', 'payload', 'processing_status', 'processing_notes', 'processed_at'])]
class PaymentEvent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }
}
