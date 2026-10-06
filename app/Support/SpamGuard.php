<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Lightweight, invisible bot protection for public forms (no CAPTCHA for
 * legitimate customers): a honeypot field and a minimum fill time carried in
 * an encrypted timestamp. Combined with rate limiting.
 */
final class SpamGuard
{
    public const HONEYPOT = 'website';

    public const TIMESTAMP = '_started';

    public static function token(): string
    {
        return encrypt(now()->getTimestamp());
    }

    public static function isBot(Request $request, int $minimumSeconds = 3): bool
    {
        if (filled($request->input(self::HONEYPOT))) {
            SecurityLog::record('honeypot_triggered', 'low', ['path' => $request->path()]);

            return true;
        }

        $started = $request->input(self::TIMESTAMP);
        if (! is_string($started)) {
            return false; // older pages or no JS: rely on rate limiting
        }

        try {
            $elapsed = now()->getTimestamp() - (int) decrypt($started);
        } catch (\Throwable) {
            return true;
        }

        if ($elapsed < $minimumSeconds) {
            SecurityLog::record('form_submitted_too_fast', 'low', ['path' => $request->path(), 'seconds' => $elapsed]);

            return true;
        }

        return false;
    }
}
