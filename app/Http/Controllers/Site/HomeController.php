<?php

namespace App\Http\Controllers\Site;

use App\Domain\Catalogue\Catalogue;
use App\Http\Controllers\Controller;
use App\Support\Seo;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(Catalogue $catalogue): View
    {
        $page = $catalogue->page('home');
        $services = $catalogue->services();
        $faqs = $catalogue->faqs('home', 5);

        $offerSlug = $page?->section('offer')['service_slug'] ?? null;
        $offer = $services->first(fn ($item) => $item['service']->slug === $offerSlug) ?? $services->first();

        $seo = Seo::make($page?->seo_title, $page?->seo_description)
            ->withJsonLd(Seo::organization())
            ->withJsonLd([
                '@type' => 'WebSite',
                'name' => \App\Support\Settings::siteName(),
                'url' => url('/'),
            ])
            ->withFaqs($faqs);

        return view('site.home', [
            'page' => $page,
            'services' => $services,
            'offer' => $offer,
            'testimonials' => $catalogue->testimonials(3),
            'articles' => $catalogue->featuredArticles(4),
            'faqs' => $faqs,
            'seo' => $seo,
        ]);
    }
}
