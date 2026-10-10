<?php

use App\Http\Middleware\AuthorizeOrderAccess;
use App\Http\Middleware\RunHeartbeat;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackPageView;
use App\Http\Middleware\VerifySelfTrigger;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => Route::group([], __DIR__.'/../routes/internal.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Which proxies may describe the client (IP, scheme, port) comes from config/trustedproxy.php,
        // read per request. The forwarded Host is never trusted: links always use APP_URL.
        $middleware->trustProxies(
            headers: SymfonyRequest::HEADER_X_FORWARDED_FOR | SymfonyRequest::HEADER_X_FORWARDED_PORT
                | SymfonyRequest::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->append(SecurityHeaders::class);

        // Signed-out customers who open an account page sign in first (the admin panel has its own login).
        $middleware->redirectGuestsTo(fn () => route('account.login'));

        // No cron needed: page views drive the maintenance heartbeat (after the response is sent).
        $middleware->appendToGroup('web', RunHeartbeat::class);

        // Webhooks are authenticated by provider signatures instead of CSRF tokens.
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'beacon']);

        $middleware->alias([
            'order.access' => AuthorizeOrderAccess::class,
            'track' => TrackPageView::class,
            'runtime.signed' => VerifySelfTrigger::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'webhooks/*') || $request->expectsJson(),
        );

        // A follow-up form left open until the session expired: explain and keep the answers (saved in the browser).
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() === 419 && $request->routeIs('orders.information')) {
                return response()->view('orders.answers-not-sent', ['order' => (string) $request->route('order')], 419);
            }
        });

        // Never leak internals to customers: technical details go to logs and error tracking only.
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'email_verification_code']);
    })->create();
