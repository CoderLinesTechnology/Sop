<?php

namespace App\Support;

use App\Domain\Notifications\AdminNotifier;
use App\Models\Order;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Records suspicious activity (invalid webhook signatures, payment
 * mismatches, order-link enumeration, coupon brute force, upload abuse,
 * prompt-injection attempts...) and alerts administrators for high-severity
 * events, throttled so an attack cannot flood the team.
 */
final class SecurityLog
{
    public static function record(
        string $type,
        string $severity = 'medium',
        array $details = [],
        ?Order $order = null,
        ?string $email = null,
    ): void {
        try {
            SecurityEvent::query()->create([
                'type' => $type,
                'severity' => $severity,
                'ip_address' => app()->runningInConsole() ? null : Request::ip(),
                'email' => $email,
                'order_id' => $order?->id,
                'path' => app()->runningInConsole() ? null : mb_substr(Request::path(), 0, 500),
                'details' => $details ?: null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to record security event', ['type' => $type, 'error' => $e->getMessage()]);
        }

        Log::channel(config('logging.default'))->warning('security.'.$type, ['severity' => $severity] + $details);

        if ($severity === 'high' && Cache::add('security-alert:'.$type, true, now()->addMinutes(10))) {
            app(AdminNotifier::class)->suspiciousActivity($type, $details, $order);
        }
    }
}
