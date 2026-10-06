<?php

namespace App\View\Composers;

use App\Domain\Pricing\PromotionResolver;
use App\Models\Service;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/** Shared data for the public layout (navigation, footer, banner). */
class SiteLayoutComposer
{
    public function compose(View $view): void
    {
        $footerServices = Cache::remember('site:footer-services', now()->addMinutes(10), fn () => Service::query()
            ->active()->ordered()->limit(6)->get(['id', 'name', 'slug'])
            ->map(fn (Service $s) => ['name' => $s->name, 'slug' => $s->slug])->all());

        $banner = app(PromotionResolver::class)->banner(now());

        $view->with([
            'siteName' => Settings::siteName(),
            'tagline' => (string) Settings::get('general.tagline'),
            'supportEmail' => Settings::supportEmail(),
            'footerServices' => $footerServices,
            'banner' => $banner ? ['text' => $banner->banner_text, 'ends_at' => $banner->show_countdown ? $banner->ends_at?->toIso8601String() : null] : null,
            'socials' => array_filter([
                'linkedin' => Settings::get('general.social_linkedin'),
                'x-social' => Settings::get('general.social_x'),
                'instagram' => Settings::get('general.social_instagram'),
                'youtube' => Settings::get('general.social_youtube'),
            ]),
            'navItems' => [
                ['label' => 'Services', 'url' => route('services.index'), 'active' => request()->routeIs('services.*')],
                ['label' => 'How It Works', 'url' => route('pages.how-it-works'), 'active' => request()->routeIs('pages.how-it-works')],
                ['label' => 'Resources', 'url' => route('resources.index'), 'active' => request()->routeIs('resources.*')],
                ['label' => 'About', 'url' => route('pages.show', 'about'), 'active' => request()->is('about')],
                ['label' => 'FAQ', 'url' => route('pages.faq'), 'active' => request()->routeIs('pages.faq')],
            ],
        ]);
    }
}
