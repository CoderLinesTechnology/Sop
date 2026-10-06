<?php

namespace App\Http\Controllers\Site;

use App\Domain\Catalogue\Catalogue;
use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Support\Analytics;
use App\Support\Seo;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function index(Catalogue $catalogue): View
    {
        $page = $catalogue->page('services');
        $services = $catalogue->services();

        $seo = Seo::make($page?->seo_title, $page?->seo_description)
            ->withBreadcrumbs([['Home', url('/')], ['Services', route('services.index')]])
            ->withJsonLd([
                '@type' => 'ItemList',
                'itemListElement' => $services->values()->map(fn ($item, $i) => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'url' => route('services.show', $item['service']->slug),
                    'name' => $item['service']->name,
                ])->all(),
            ]);

        return view('site.services.index', [
            'page' => $page,
            'services' => $services,
            'testimonial' => $catalogue->testimonials(3)->first(),
            'seo' => $seo,
        ]);
    }

    public function show(Request $request, Catalogue $catalogue, string $slug): View
    {
        $service = $catalogue->service($slug);
        abort_if($service === null, 404);

        $quote = $catalogue->quote($service);
        Analytics::record(AnalyticsEvent::SERVICE_VIEW, $request, ['service_id' => $service->id]);

        $seo = Seo::make($service->seo_title ?: $service->name, $service->seo_description ?: $service->short_description)
            ->withBreadcrumbs([['Home', url('/')], ['Services', route('services.index')], [$service->name, route('services.show', $service->slug)]])
            ->withJsonLd([
                '@type' => 'Service',
                'name' => $service->name,
                'description' => Str::limit(strip_tags((string) Str::markdown((string) ($service->description ?: $service->short_description))), 300),
                'provider' => ['@type' => 'Organization', 'name' => Settings::siteName(), 'url' => url('/')],
                'areaServed' => 'Worldwide',
                'offers' => [
                    '@type' => 'Offer',
                    'price' => number_format($quote->total / 100, 2, '.', ''),
                    'priceCurrency' => $quote->currency,
                    'availability' => 'https://schema.org/InStock',
                    'url' => route('order.start', $service->slug),
                ],
            ])
            ->withFaqs($service->faqs);

        if ($service->og_image_path) {
            $seo->image = $service->og_image_path;
        }

        return view('site.services.show', [
            'service' => $service,
            'quote' => $quote,
            'faqs' => $service->faqs->isNotEmpty() ? $service->faqs : $catalogue->faqs('general', 6),
            'seo' => $seo,
        ]);
    }
}
