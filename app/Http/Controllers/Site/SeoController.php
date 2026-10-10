<?php

namespace App\Http\Controllers\Site;

use App\Domain\Catalogue\Catalogue;
use App\Domain\Seo\IndexNow;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Service;
use App\Support\Money;
use App\Support\ResponsiveImage;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Machine-readable views of the site for search engines and AI assistants:
 * sitemap, robots.txt, llms.txt, the guides feed, the web manifest and the
 * IndexNow key file. Everything is built from published content only and
 * cached under site:* keys that Catalogue::flush() clears on every edit.
 */
class SeoController extends Controller
{
    /** Assistants that fetch pages to answer a user and cite or link them (they bring visitors). */
    public const AI_SEARCH_AGENTS = [
        'OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Perplexity-User', 'Claude-SearchBot', 'Claude-User',
        'DuckAssistBot', 'Applebot',
    ];

    /** Crawlers that collect text to train AI models. */
    public const AI_TRAINING_AGENTS = [
        'GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Meta-ExternalAgent', 'Bytespider',
    ];

    /** Never useful in search: private, transactional or machine endpoints. */
    private const PRIVATE_PATHS = ['/o/', '/checkout/', '/webhooks/', '/internal/', '/system/', '/uploads/', '/beacon', '/dev/'];

    private const FEED_SIZE = 20;

    private const LLMS_ARTICLES = 50;

    public function sitemap(): Response
    {
        $xml = Cache::remember(Catalogue::CACHE_TAG_PREFIX.'sitemap', now()->addHour(), function () {
            $pages = Page::query()->published()->get(['slug', 'updated_at'])->keyBy('slug');
            $services = Service::query()->active()->ordered()->get(['slug', 'updated_at']);
            $articles = Article::query()->published()->latest('published_at')
                ->get(['slug', 'title', 'cover_image_path', 'article_category_id', 'updated_at']);
            $lastmod = fn (?Carbon ...$dates): ?string => collect($dates)->filter()->max()?->toAtomString();
            $page = fn (string $slug): ?Carbon => $pages->get($slug)?->updated_at;
            $faqUpdated = Faq::query()->max('updated_at');

            $urls = [
                ['loc' => url('/'), 'lastmod' => $lastmod($page('home'), $services->max('updated_at')), 'priority' => '1.0', 'changefreq' => 'weekly'],
                ['loc' => route('services.index'), 'lastmod' => $lastmod($page('services'), $services->max('updated_at')), 'priority' => '0.9', 'changefreq' => 'weekly'],
                ['loc' => route('pages.how-it-works'), 'lastmod' => $lastmod($page('how-it-works')), 'priority' => '0.7', 'changefreq' => 'monthly'],
                ['loc' => route('resources.index'), 'lastmod' => $lastmod($page('resources'), $articles->max('updated_at')), 'priority' => '0.8', 'changefreq' => 'weekly'],
                ['loc' => route('pages.faq'), 'lastmod' => $lastmod($page('faq'), $faqUpdated ? Carbon::parse($faqUpdated) : null), 'priority' => '0.6', 'changefreq' => 'monthly'],
                ['loc' => route('contact.show'), 'lastmod' => $lastmod($page('contact')), 'priority' => '0.4', 'changefreq' => 'yearly'],
            ];

            foreach (PageController::STANDARD_PAGES as $slug) {
                if ($pages->has($slug)) {
                    $urls[] = ['loc' => route('pages.show', $slug), 'lastmod' => $lastmod($page($slug)), 'priority' => $slug === 'about' ? '0.5' : '0.2', 'changefreq' => 'yearly'];
                }
            }

            foreach ($services as $service) {
                $urls[] = ['loc' => route('services.show', $service->slug), 'lastmod' => $lastmod($service->updated_at), 'priority' => '0.9', 'changefreq' => 'weekly'];
            }

            // Only categories substantial enough to be indexed (see ArticleController).
            $byCategory = $articles->groupBy('article_category_id');
            foreach (ArticleCategory::query()->active()->get(['id', 'slug']) as $category) {
                $items = $byCategory->get($category->id, collect());
                if ($items->count() >= ArticleController::MIN_INDEXABLE_CATEGORY_ARTICLES) {
                    $urls[] = ['loc' => route('resources.category', $category->slug), 'lastmod' => $lastmod($items->max('updated_at')), 'priority' => '0.5', 'changefreq' => 'weekly'];
                }
            }

            foreach ($articles as $article) {
                $image = $article->cover_image_path ? (ResponsiveImage::resolve($article->cover_image_path)['src'] ?? null) : null;
                $urls[] = [
                    'loc' => route('resources.show', $article->slug),
                    'lastmod' => $lastmod($article->updated_at),
                    'priority' => '0.7',
                    'changefreq' => 'monthly',
                    'image' => $image ? url($image) : null,
                ];
            }

            return view('site.sitemap', ['urls' => $urls])->render();
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function robots(): Response
    {
        if (! app()->isProduction()) {
            return $this->text("User-agent: *\nDisallow: /\n");
        }

        $groups = [
            'Search engines and other crawlers' => [['*'], true],
            'AI search and assistants (they cite and link to the pages they use)' => [self::AI_SEARCH_AGENTS, (bool) Settings::get('seo.ai_search_crawlers', true)],
            'AI model training' => [self::AI_TRAINING_AGENTS, (bool) Settings::get('seo.ai_training_crawlers', true)],
        ];

        $lines = [];
        foreach ($groups as $comment => [$agents, $allowed]) {
            $lines[] = '# '.$comment;
            foreach ($agents as $agent) {
                $lines[] = 'User-agent: '.$agent;
            }
            // A crawler that matches a named group ignores "*", so every group repeats the private paths.
            if ($allowed) {
                $lines[] = 'Allow: /';
                foreach (self::PRIVATE_PATHS as $path) {
                    $lines[] = 'Disallow: '.$path;
                }
            } else {
                $lines[] = 'Disallow: /';
            }
            $lines[] = '';
        }
        $lines[] = 'Sitemap: '.route('sitemap');

        return $this->text(implode("\n", $lines)."\n");
    }

    /** A plain-text map of the site for AI assistants (https://llmstxt.org). */
    public function llms(Catalogue $catalogue): Response
    {
        // Short cache: prices follow promotions that start and end on a schedule.
        $text = Cache::remember(Catalogue::CACHE_TAG_PREFIX.'llms', now()->addMinutes(10), function () use ($catalogue) {
            $site = Settings::siteName();
            $plain = fn (?string $markdown): string => trim(preg_replace('/\s+/', ' ', strip_tags((string) Str::markdown((string) $markdown, ['html_input' => 'strip']))) ?? '');

            $out = ['# '.$site, ''];
            $out[] = '> '.trim(Settings::get('general.tagline').' '.Settings::get('seo.default_description'));
            $out[] = '';
            $out[] = $site.' prepares personalised application documents for university, scholarship and job applications. '
                .'Customers order online, pay securely with Paystack and receive their document (PDF and Word) by email. '
                .'Prices are in '.Settings::currency().'.';
            $out[] = '';

            $out[] = '## Services';
            foreach ($catalogue->services() as ['service' => $service, 'quote' => $quote]) {
                $out[] = sprintf('- [%s](%s): %s Price: %s. Delivery: %s.',
                    $service->name,
                    route('services.show', $service->slug),
                    rtrim($plain($service->short_description), '.').'.',
                    Money::format($quote->total, $quote->currency),
                    $service->deliveryLabel(),
                );
            }
            $out[] = '';

            $out[] = '## Key pages';
            foreach ([
                [route('pages.how-it-works'), 'How it works', 'how-it-works'],
                [route('services.index'), 'All services and prices', 'services'],
                [route('resources.index'), 'Writing guides', 'resources'],
                [route('pages.faq'), 'Frequently asked questions', 'faq'],
                [route('contact.show'), 'Contact support', 'contact'],
            ] as [$url, $label, $slug]) {
                $description = $catalogue->page($slug)?->seo_description;
                $out[] = '- ['.$label.']('.$url.')'.($description ? ': '.$plain($description) : '');
            }
            $out[] = '';

            $articles = Article::query()->published()->latest('published_at')->limit(self::LLMS_ARTICLES)->get(['slug', 'title', 'excerpt']);
            if ($articles->isNotEmpty()) {
                $out[] = '## Guides';
                foreach ($articles as $article) {
                    $out[] = '- ['.$article->title.']('.route('resources.show', $article->slug).')'.($article->excerpt ? ': '.$plain($article->excerpt) : '');
                }
                $out[] = '';
            }

            $faqs = $catalogue->faqs('home', 50)->concat($catalogue->faqs('general', 50))->unique('question');
            if ($faqs->isNotEmpty()) {
                $out[] = '## FAQ';
                foreach ($faqs as $faq) {
                    $out[] = '### '.$faq->question;
                    $out[] = $plain($faq->answer);
                    $out[] = '';
                }
            }

            $out[] = '## Policies';
            foreach (['privacy-policy' => 'Privacy policy', 'terms' => 'Terms of service', 'refund-policy' => 'Refund policy'] as $slug => $label) {
                $out[] = '- ['.$label.']('.route('pages.show', $slug).')';
            }
            $out[] = '';
            $out[] = 'Support: '.Settings::supportEmail();

            return implode("\n", $out)."\n";
        });

        return response($text, 200, ['Content-Type' => 'text/markdown; charset=UTF-8', 'Cache-Control' => 'public, max-age=600']);
    }

    /** Atom feed of the latest guides. */
    public function feed(Catalogue $catalogue): Response
    {
        $xml = Cache::remember(Catalogue::CACHE_TAG_PREFIX.'feed', now()->addHour(), function () use ($catalogue) {
            /** @var Collection<int, Article> $articles */
            $articles = Article::query()->published()->with('category:id,name')->latest('published_at')->limit(self::FEED_SIZE)
                ->get(['slug', 'title', 'excerpt', 'author_name', 'published_at', 'created_at', 'updated_at', 'article_category_id']);

            return view('site.feed', [
                'articles' => $articles,
                'siteName' => Settings::siteName(),
                'subtitle' => $catalogue->page('resources')?->seo_description,
                'updated' => ($articles->max('updated_at') ?? now())->toAtomString(),
            ])->render();
        });

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => Settings::siteName(),
            'short_name' => Settings::siteName(),
            'description' => Settings::get('general.tagline'),
            'start_url' => '/',
            'display' => 'browser',
            'background_color' => '#ffffff',
            'theme_color' => '#12403a',
            'icons' => [
                ['src' => asset('apple-touch-icon.png'), 'sizes' => '180x180', 'type' => 'image/png'],
                ['src' => asset('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=86400']);
    }

    /** The IndexNow key file (https://www.indexnow.org/documentation): proves the site owns the key. */
    public function indexNowKey(IndexNow $indexNow, string $key): Response
    {
        abort_unless(hash_equals($indexNow->key(), $key), 404);

        return $this->text($key);
    }

    private function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
