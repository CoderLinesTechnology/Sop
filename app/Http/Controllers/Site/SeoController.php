<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Service;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SeoController extends Controller
{
    public function sitemap(): Response
    {
        $xml = Cache::remember('site:sitemap', now()->addHour(), function () {
            $urls = [
                ['loc' => url('/'), 'priority' => '1.0', 'changefreq' => 'weekly'],
                ['loc' => route('services.index'), 'priority' => '0.9', 'changefreq' => 'weekly'],
                ['loc' => route('pages.how-it-works'), 'priority' => '0.7', 'changefreq' => 'monthly'],
                ['loc' => route('resources.index'), 'priority' => '0.8', 'changefreq' => 'weekly'],
                ['loc' => route('pages.faq'), 'priority' => '0.6', 'changefreq' => 'monthly'],
                ['loc' => route('contact.show'), 'priority' => '0.4', 'changefreq' => 'yearly'],
            ];

            foreach (['about', 'privacy-policy', 'terms', 'refund-policy', 'cookie-policy'] as $page) {
                $urls[] = ['loc' => route('pages.show', $page), 'priority' => $page === 'about' ? '0.5' : '0.2', 'changefreq' => 'yearly'];
            }

            foreach (Service::query()->active()->ordered()->get(['slug', 'updated_at']) as $service) {
                $urls[] = ['loc' => route('services.show', $service->slug), 'lastmod' => $service->updated_at?->toDateString(), 'priority' => '0.9', 'changefreq' => 'weekly'];
            }

            foreach (ArticleCategory::query()->active()->get(['slug']) as $category) {
                $urls[] = ['loc' => route('resources.category', $category->slug), 'priority' => '0.5', 'changefreq' => 'weekly'];
            }

            foreach (Article::query()->published()->get(['slug', 'updated_at']) as $article) {
                $urls[] = ['loc' => route('resources.show', $article->slug), 'lastmod' => $article->updated_at?->toDateString(), 'priority' => '0.7', 'changefreq' => 'monthly'];
            }

            return view('site.sitemap', ['urls' => $urls])->render();
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function robots(): Response
    {
        $admin = trim((string) config('statementra.security.admin_path', 'admin'), '/');

        $lines = app()->isProduction()
            ? [
                'User-agent: *',
                'Disallow: /'.$admin,
                'Disallow: /start',
                'Disallow: /checkout',
                'Disallow: /o/',
                'Disallow: /account',
                'Disallow: /find-my-order',
                'Disallow: /webhooks',
                'Allow: /',
                '',
                'Sitemap: '.route('sitemap'),
            ]
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
