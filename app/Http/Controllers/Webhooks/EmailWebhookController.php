<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\EmailStatus;
use App\Http\Controllers\Controller;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Delivery tracking from the email provider (Resend or Postmark). Updates
 * the emails log so administrators can see delivered / bounced / complained
 * states, and flags failed document deliveries.
 */
class EmailWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $raw = (string) $request->getContent();

        $authorised = match ($provider) {
            'resend' => $this->verifyResend($request, $raw),
            'postmark' => $this->verifyPostmark($request),
            default => false,
        };

        if (! $authorised) {
            SecurityLog::record('invalid_email_webhook', 'medium', ['provider' => $provider]);

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $payload = (array) json_decode($raw, true);
        [$type, $messageId, $occurredAt] = $provider === 'resend'
            ? [(string) ($payload['type'] ?? ''), (string) data_get($payload, 'data.email_id', ''), $payload['created_at'] ?? null]
            : [(string) ($payload['RecordType'] ?? ''), (string) ($payload['MessageID'] ?? ''), $payload['DeliveredAt'] ?? $payload['BouncedAt'] ?? $payload['ReceivedAt'] ?? null];

        $email = $messageId !== '' ? EmailMessage::query()->where('provider_message_id', $messageId)->first() : null;
        if (! $email && ($uuid = data_get($payload, 'Metadata.email_uuid') ?? data_get($payload, 'data.tags.email_uuid'))) {
            $email = EmailMessage::query()->where('uuid', $uuid)->first();
        }

        EmailEvent::query()->create([
            'email_id' => $email?->id,
            'provider' => $provider,
            'event_type' => mb_substr($type, 0, 40),
            'provider_message_id' => $messageId ?: null,
            'payload' => $payload,
            'occurred_at' => $occurredAt ? Carbon::parse($occurredAt) : now(),
            'created_at' => now(),
        ]);

        if ($email) {
            $status = $this->mapStatus($provider, $type);
            if ($status) {
                $email->forceFill(array_filter([
                    'status' => $status,
                    'delivered_at' => $status === EmailStatus::Delivered ? now() : null,
                    'failed_at' => in_array($status, [EmailStatus::Bounced, EmailStatus::Complained], true) ? now() : null,
                ]))->save();

                if ($status === EmailStatus::Bounced && data_get($email->meta, 'purpose') === 'delivery') {
                    app(\App\Domain\Delivery\DocumentDelivery::class)->markFailed($email, 'The email provider reported a bounce.');
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    private function mapStatus(string $provider, string $type): ?EmailStatus
    {
        return match ($provider === 'resend' ? $type : strtolower($type)) {
            'email.delivered', 'delivery' => EmailStatus::Delivered,
            'email.bounced', 'bounce' => EmailStatus::Bounced,
            'email.complained', 'spamcomplaint' => EmailStatus::Complained,
            'email.delivery_delayed' => EmailStatus::Deferred,
            default => null,
        };
    }

    /** Resend signs webhooks with Svix: HMAC-SHA256 over "id.timestamp.body". */
    private function verifyResend(Request $request, string $raw): bool
    {
        $secret = (string) config('statementra.email.resend_webhook_secret');
        $id = (string) $request->header('svix-id');
        $timestamp = (string) $request->header('svix-timestamp');
        $signatures = (string) $request->header('svix-signature');

        if ($secret === '' || $id === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);
        if ($key === false) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$raw, $key, true));
        foreach (explode(' ', $signatures) as $signature) {
            [$version, $value] = array_pad(explode(',', $signature, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $value)) {
                return true;
            }
        }

        return false;
    }

    private function verifyPostmark(Request $request): bool
    {
        $token = (string) config('statementra.email.postmark_webhook_token');
        $given = (string) ($request->header('X-Webhook-Token') ?? $request->query('token', ''));

        return $token !== '' && hash_equals($token, $given);
    }
}
