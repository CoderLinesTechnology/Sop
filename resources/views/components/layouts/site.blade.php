@props(['seo' => null, 'scripts' => [], 'bare' => false, 'mainClass' => ''])
@php($seo ??= \App\Support\Seo::make())
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo->fullTitle() }}</title>
    @if ($seo->description)
        <meta name="description" content="{{ $seo->description }}">
    @endif
    <meta name="robots" content="{{ $seo->index && app()->isProduction() ? 'index, follow' : 'noindex, nofollow' }}">
    <link rel="canonical" href="{{ $seo->canonicalUrl() }}">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:type" content="{{ $seo->type }}">
    <meta property="og:title" content="{{ $seo->fullTitle() }}">
    @if ($seo->description)
        <meta property="og:description" content="{{ $seo->description }}">
    @endif
    <meta property="og:url" content="{{ $seo->canonicalUrl() }}">
    <meta property="og:image" content="{{ $seo->imageUrl() }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seo->fullTitle() }}">
    @if ($seo->description)
        <meta name="twitter:description" content="{{ $seo->description }}">
    @endif
    <meta name="twitter:image" content="{{ $seo->imageUrl() }}">
    @if ($verification = \App\Support\Settings::get('seo.google_site_verification'))
        <meta name="google-site-verification" content="{{ $verification }}">
    @endif
    <meta name="theme-color" content="#12403A">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js', ...$scripts])
    @foreach ($seo->jsonLd as $schema)
        <script type="application/ld+json" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    @endforeach
    {{ $head ?? '' }}
</head>
<body class="flex min-h-dvh flex-col">
    <a href="#main" class="sr-only z-50 rounded-md bg-white px-4 py-2 font-semibold text-brand-700 focus:not-sr-only focus:fixed focus:top-3 focus:left-3">Skip to content</a>

    @if ($banner && ! $bare)
        <div class="bg-brand-800 text-white">
            <div class="container-site flex flex-wrap items-center justify-center gap-x-3 gap-y-1 py-2 text-center text-[0.82rem]">
                <span>{{ $banner['text'] }}</span>
                @if ($banner['ends_at'])
                    <span class="font-semibold tabular-nums" data-countdown data-ends-at="{{ $banner['ends_at'] }}" data-server-now="{{ now()->toIso8601String() }}">
                        Ends in <span data-unit="compact">—</span>
                    </span>
                @endif
                <a href="{{ route('order.start') }}" class="font-semibold underline underline-offset-4">Get started</a>
            </div>
        </div>
    @endif

    <x-site.header :nav-items="$navItems" :site-name="$siteName" :bare="$bare" />

    <main id="main" class="flex-1 {{ $mainClass }}" tabindex="-1">
        {{ $slot }}
    </main>

    <x-site.footer :site-name="$siteName" :tagline="$tagline" :services="$footerServices" :socials="$socials" :compact="$bare" />
</body>
</html>
