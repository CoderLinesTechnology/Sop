<?php

use App\Http\Middleware\AuthorizeOrderAccess;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackPageView;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a load balancer / CDN (e.g. Cloudflare) the client IP and scheme come from forwarded headers.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: SymfonyRequest::HEADER_X_FORWARDED_FOR | SymfonyRequest::HEADER_X_FORWARDED_HOST
                | SymfonyRequest::HEADER_X_FORWARDED_PORT | SymfonyRequest::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->append(SecurityHeaders::class);

        // Webhooks are authenticated by provider signatures instead of CSRF tokens.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        $middleware->alias([
            'order.access' => AuthorizeOrderAccess::class,
            'track' => TrackPageView::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'webhooks/*') || $request->expectsJson(),
        );

        // Never leak internals to customers: technical details go to logs and error tracking only.
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'email_verification_code']);
    })->create();
