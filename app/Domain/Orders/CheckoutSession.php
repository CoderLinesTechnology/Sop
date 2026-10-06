<?php

namespace App\Domain\Orders;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Binds a draft order and its uploads to the browser that created them.
 *
 * A random token lives in an encrypted, HttpOnly cookie; only its SHA-256 hash
 * is stored on the order / uploaded files. Without the cookie, a draft order
 * or an upload cannot be read, edited or paid for, even with its reference.
 */
final class CheckoutSession
{
    public const COOKIE = 'st_checkout';

    private const LIFETIME_MINUTES = 60 * 24 * 3;

    private const REQUEST_ATTRIBUTE = '_checkout_token';

    public static function token(Request $request): string
    {
        if ($request->attributes->has(self::REQUEST_ATTRIBUTE)) {
            return $request->attributes->get(self::REQUEST_ATTRIBUTE);
        }

        $token = $request->cookie(self::COOKIE);
        if (! is_string($token) || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }

        // (Re)issue the cookie so its lifetime slides while the customer is active.
        Cookie::queue(cookie(
            self::COOKIE,
            $token,
            self::LIFETIME_MINUTES,
            path: '/',
            secure: request()->isSecure() || app()->isProduction(),
            httpOnly: true,
            sameSite: 'lax',
        ));

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $token);

        return $token;
    }

    public static function hash(Request $request): string
    {
        return hash('sha256', self::token($request));
    }

    /** Hash of the existing cookie only (does not mint a new token). */
    public static function existingHash(Request $request): ?string
    {
        $token = $request->attributes->get(self::REQUEST_ATTRIBUTE) ?? $request->cookie(self::COOKIE);

        return is_string($token) && $token !== '' ? hash('sha256', $token) : null;
    }

    public static function owns(Request $request, Order $order): bool
    {
        $hash = self::existingHash($request);

        return $hash !== null
            && is_string($order->checkout_token_hash)
            && hash_equals($order->checkout_token_hash, $hash);
    }
}
