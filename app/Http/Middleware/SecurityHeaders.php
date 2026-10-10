<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for every response.
 *
 * Public pages get a strict nonce-based Content Security Policy (no inline
 * scripts or styles without the per-request nonce, no eval, no third-party
 * origins except Paystack as a form/redirect target). The Filament admin
 * needs Alpine's standard build, so it receives a separate policy that still
 * restricts every source to this origin.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::cspNonce() ?: base64_encode(random_bytes(18));
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $isAdmin = $request->is(trim((string) config('statementra.security.admin_path', 'admin'), '/').'*')
            || $request->is('livewire*', 'filament*');

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $headers->set('Referrer-Policy', $request->is('o/*', 'checkout/*', 'account*') ? 'no-referrer' : 'strict-origin-when-cross-origin');

        if (config('statementra.security.hsts') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
        }

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $isAdmin ? $this->adminPolicy() : $this->publicPolicy($nonce));
        }

        if ($request->is('o/*', 'checkout/*', 'account*', 'start*') || $isAdmin) {
            $headers->set('Cache-Control', 'no-store, private');
            // Kept out of search results by header, so robots.txt never has to name the admin path.
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        // Do not advertise the PHP version.
        $headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }

    private function publicPolicy(string $nonce): string
    {
        $self = "'self'";
        $script = [$self, "'nonce-{$nonce}'"];
        $style = [$self, "'nonce-{$nonce}'"];
        $connect = [$self];
        $frame = ["'none'"];
        $formAction = [$self, 'https://checkout.paystack.com'];
        $img = [$self, 'data:', 'blob:'];

        if (config('statementra.security.turnstile_site_key')) {
            $script[] = 'https://challenges.cloudflare.com';
            $frame = ['https://challenges.cloudflare.com'];
        }

        if ($mediaUrl = config('filesystems.disks.'.config('statementra.storage.public_disk').'.url')) {
            $origin = parse_url($mediaUrl, PHP_URL_SCHEME).'://'.parse_url($mediaUrl, PHP_URL_HOST);
            if (parse_url($mediaUrl, PHP_URL_HOST) && ! str_contains($origin, (string) parse_url((string) config('app.url'), PHP_URL_HOST))) {
                $img[] = $origin;
            }
        }

        if (Vite::isRunningHot()) {
            $hot = rtrim((string) file_get_contents(public_path('hot')));
            $script[] = $hot;
            $style[] = $hot;
            $connect[] = $hot;
            $connect[] = str_replace(['http://', 'https://'], ['ws://', 'wss://'], $hot);
        }

        $directives = [
            'default-src' => [$self],
            'script-src' => $script,
            'style-src' => $style,
            'img-src' => $img,
            'font-src' => [$self, 'data:'],
            'connect-src' => $connect,
            'frame-src' => $frame,
            'frame-ancestors' => ["'none'"],
            'form-action' => $formAction,
            'base-uri' => [$self],
            'object-src' => ["'none'"],
            'manifest-src' => [$self],
            'worker-src' => [$self],
        ];

        $policy = implode('; ', array_map(fn ($k, $v) => $k.' '.implode(' ', $v), array_keys($directives), $directives));

        return app()->isProduction() ? $policy.'; upgrade-insecure-requests' : $policy;
    }

    private function adminPolicy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-src 'self'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }
}
