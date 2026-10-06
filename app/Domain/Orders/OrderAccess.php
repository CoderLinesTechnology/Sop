<?php

namespace App\Domain\Orders;

use App\Models\DocumentVersion;
use App\Models\Order;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * How customers reach their order without an account.
 *
 * Emails carry signed, expiring URLs (HMAC with the app key) that embed the
 * order's public_id and access_version. Opening a valid link grants the
 * browser session access to that order, so forms and downloads on the page
 * work without re-signing. Incrementing access_version revokes every link
 * previously issued for the order.
 */
final class OrderAccess
{
    private const SESSION_KEY = 'order_access';

    public static function statusUrl(Order $order, ?int $days = null): string
    {
        $days ??= (int) Settings::get('orders.order_link_days', 30);

        return URL::temporarySignedRoute('orders.show', now()->addDays(max(1, $days)), [
            'order' => $order->public_id,
            'v' => $order->access_version,
        ]);
    }

    public static function downloadUrl(Order $order, DocumentVersion $version, string $format, bool $inline = false, ?int $days = null): string
    {
        $days ??= (int) Settings::get('email.document_link_days', 14);

        return URL::temporarySignedRoute('orders.download', now()->addDays(max(1, $days)), array_filter([
            'order' => $order->public_id,
            'version' => $version->uuid,
            'format' => $format,
            'v' => $order->access_version,
            'inline' => $inline ? 1 : null,
        ]));
    }

    /** Whether the request carries a valid, unexpired signature for this order's current access version. */
    public static function hasValidLink(Request $request, Order $order): bool
    {
        return $request->hasValidSignature()
            && (int) $request->query('v') === (int) $order->access_version;
    }

    public static function grant(Request $request, Order $order): void
    {
        $grants = (array) $request->session()->get(self::SESSION_KEY, []);
        unset($grants[$order->public_id]);
        $grants[$order->public_id] = (int) $order->access_version;

        // Keep the session small: remember the 20 most recent orders.
        $request->session()->put(self::SESSION_KEY, array_slice($grants, -20, null, true));
    }

    public static function hasGrant(Request $request, Order $order): bool
    {
        if (! $request->hasSession()) {
            return false;
        }
        $grants = (array) $request->session()->get(self::SESSION_KEY, []);

        return array_key_exists($order->public_id, $grants)
            && (int) $grants[$order->public_id] === (int) $order->access_version;
    }

    /** A signed-in customer whose verified email matches the order may view it. */
    public static function ownsViaAccount(Request $request, Order $order): bool
    {
        $user = $request->user();

        return $user !== null
            && $user->email_verified_at !== null
            && ($order->user_id === $user->id || hash_equals($order->email, strtolower($user->email)));
    }

    public static function canAccess(Request $request, Order $order): bool
    {
        return self::hasGrant($request, $order) || self::ownsViaAccount($request, $order);
    }

    /** Revoke every previously issued customer link for this order. */
    public static function rotate(Order $order): void
    {
        $before = $order->access_version;
        $order->increment('access_version');
        Audit::log('order.access_links_rotated', $order, ['access_version' => $before], ['access_version' => $order->access_version]);
    }
}
