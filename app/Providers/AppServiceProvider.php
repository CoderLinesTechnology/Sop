<?php

namespace App\Providers;

use App\Domain\Catalogue\Catalogue;
use App\Domain\Payments\Paystack\MockPaystackGateway;
use App\Domain\Payments\Paystack\PaystackClient;
use App\Domain\Payments\Paystack\PaystackGateway;
use App\Domain\Pricing\PromotionResolver;
use App\Support\SecurityLog;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaystackGateway::class, function () {
            $mode = config('statementra.paystack.mode');
            if ($mode === 'mock') {
                if ($this->app->isProduction()) {
                    throw new RuntimeException('PAYSTACK_MODE=mock is not allowed in production.');
                }

                return new MockPaystackGateway;
            }

            return new PaystackClient;
        });

        // Memoises running promotions for the duration of one request / job.
        $this->app->scoped(PromotionResolver::class);
    }

    public function boot(): void
    {
        if ($this->app->isProduction()) {
            URL::forceHttps(str_starts_with((string) config('app.url'), 'https://'));
            $this->guardProductionConfiguration();
        }

        Model::automaticallyEagerLoadRelationships();

        Password::defaults(fn () => Password::min(12)->letters()->mixedCase()->numbers()->symbols());

        $this->configureRateLimiting();

        View::composer('components.layouts.site', \App\View\Composers\SiteLayoutComposer::class);

        // Admin edits to public content are visible immediately.
        foreach ([
            \App\Models\Service::class, \App\Models\ServiceField::class, \App\Models\Page::class, \App\Models\Faq::class,
            \App\Models\Article::class, \App\Models\ArticleCategory::class, \App\Models\Testimonial::class, \App\Models\Promotion::class,
        ] as $model) {
            $model::saved(fn () => Catalogue::flush());
            $model::deleted(fn () => Catalogue::flush());
        }
    }

    private function configureRateLimiting(): void
    {
        $reject = fn (string $type) => function (Request $request, array $headers) use ($type) {
            SecurityLog::record('rate_limited', 'low', ['limiter' => $type]);

            return $request->expectsJson()
                ? response()->json(['message' => 'Too many requests. Please wait a moment and try again.'], 429, $headers)
                : response()->view('errors.429', [], 429, $headers);
        };

        RateLimiter::for('uploads', fn (Request $r) => [
            Limit::perMinute(15)->by('up-m:'.$r->ip())->response($reject('uploads')),
            Limit::perHour((int) Settings::get('security.rate_limit_uploads_per_hour', 40))->by('up-h:'.$r->ip())->response($reject('uploads')),
        ]);

        RateLimiter::for('order-submit', fn (Request $r) => [
            Limit::perMinute(10)->by('os-m:'.$r->ip())->response($reject('order-submit')),
            Limit::perHour((int) Settings::get('security.rate_limit_orders_per_hour', 12) * 3)->by('os-h:'.$r->ip())->response($reject('order-submit')),
        ]);

        RateLimiter::for('checkout', fn (Request $r) => Limit::perHour((int) Settings::get('security.rate_limit_orders_per_hour', 12))->by('co:'.$r->ip())->response($reject('checkout')));
        RateLimiter::for('quote', fn (Request $r) => Limit::perMinute(30)->by('q:'.$r->ip())->response($reject('quote')));
        RateLimiter::for('checkout-callback', fn (Request $r) => Limit::perMinute(30)->by('cb:'.$r->ip())->response($reject('checkout-callback')));
        RateLimiter::for('order-access', fn (Request $r) => Limit::perMinute(60)->by('oa:'.$r->ip())->response($reject('order-access')));
        RateLimiter::for('order-actions', fn (Request $r) => Limit::perHour(30)->by('oact:'.$r->ip())->response($reject('order-actions')));
        RateLimiter::for('forms', fn (Request $r) => [
            Limit::perMinute(5)->by('f-m:'.$r->ip())->response($reject('forms')),
            Limit::perHour(20)->by('f-h:'.$r->ip())->response($reject('forms')),
        ]);
        RateLimiter::for('magic-link', fn (Request $r) => [
            Limit::perHour(10)->by('ml-ip:'.$r->ip())->response($reject('magic-link')),
            Limit::perHour(3)->by('ml-email:'.strtolower((string) $r->input('email')))->response($reject('magic-link')),
        ]);
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(600)->by('wh:'.$r->ip()));
        RateLimiter::for('beacon', fn (Request $r) => Limit::perMinute(120)->by('b:'.$r->ip()));
    }

    /** Fail fast on dangerous production misconfiguration. */
    private function guardProductionConfiguration(): void
    {
        if (config('app.debug')) {
            throw new RuntimeException('APP_DEBUG must be false in production.');
        }
        if (config('statementra.ai.provider') === 'fake') {
            throw new RuntimeException('AI_PROVIDER=fake is not allowed in production.');
        }
        $secret = (string) config('statementra.paystack.secret_key');
        if (config('statementra.paystack.mode') === 'live' && $secret !== '' && ! str_starts_with($secret, 'sk_live_')) {
            throw new RuntimeException('PAYSTACK_MODE=live requires a live secret key (sk_live_...).');
        }
        if (config('statementra.paystack.mode') === 'test' && $secret !== '' && ! str_starts_with($secret, 'sk_test_')) {
            throw new RuntimeException('PAYSTACK_MODE=test requires a test secret key (sk_test_...).');
        }
    }
}
