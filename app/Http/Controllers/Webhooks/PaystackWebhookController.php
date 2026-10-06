<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Payments\PaymentEventProcessor;
use App\Domain\Payments\Paystack\WebhookSignature;
use App\Http\Controllers\Controller;
use App\Models\PaymentEvent;
use App\Support\Runtime\AfterResponse;
use App\Support\SecurityLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Paystack webhook receiver.
 *
 * 1. Verify X-Paystack-Signature (HMAC-SHA512 of the raw body) before parsing.
 * 2. Store the delivery once (unique payload hash) — duplicates and replays
 *    are acknowledged without being processed again.
 * 3. Queue processing and answer 200 quickly; the job re-verifies the
 *    transaction with Paystack's API before changing anything.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $raw = (string) $request->getContent();
        $valid = WebhookSignature::isValid($raw, $request->header('X-Paystack-Signature'));

        if ($valid && config('statementra.paystack.enforce_webhook_ips')
            && ! IpUtils::checkIp((string) $request->ip(), config('statementra.paystack.webhook_ips', []))) {
            $valid = false;
            SecurityLog::record('webhook_unexpected_ip', 'high', ['ip' => $request->ip()]);
        }

        $payload = json_decode($raw, true);
        $payload = is_array($payload) ? $payload : [];
        $type = mb_substr((string) ($payload['event'] ?? 'unknown'), 0, 60);
        $reference = data_get($payload, 'data.reference');

        try {
            $event = PaymentEvent::query()->create([
                'provider' => 'paystack',
                'event_type' => $type,
                'reference' => is_string($reference) ? mb_substr($reference, 0, 64) : null,
                'provider_event_id' => is_scalar(data_get($payload, 'data.id')) ? (string) data_get($payload, 'data.id') : null,
                'payload_hash' => hash('sha256', $raw),
                'signature_valid' => $valid,
                'source_ip' => $request->ip(),
                // Unverified payloads are not stored, only their fingerprint.
                'payload' => $valid ? $payload : null,
                'processing_status' => $valid ? 'pending' : 'rejected',
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']);
        }

        if (! $valid) {
            SecurityLog::record('invalid_webhook_signature', 'high', ['event' => $type, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        // Acknowledge at once; verify and apply right after the response.
        // Pending events are retried by the heartbeat if this attempt dies.
        $eventId = $event->id;
        AfterResponse::run('payment-event:'.$eventId, fn () => app(PaymentEventProcessor::class)->process($eventId), timeLimitSeconds: 120);

        return response()->json(['status' => 'accepted']);
    }
}
