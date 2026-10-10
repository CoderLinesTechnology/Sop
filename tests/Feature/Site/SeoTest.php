<?php

use App\Domain\Seo\IndexNow;
use App\Http\Controllers\Site\SeoController;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Service;
use App\Support\Settings;
use Database\Seeders\ArticleSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('statementra.runtime.tasks', []);
    Settings::flush();
});

function seedSite(): void
{
    test()->seed([ServiceCatalogSeeder::class, ContentSeeder::class, ArticleSeeder::class]);
}

/** Run a request as if in production (robots.txt and IndexNow only act there). */
function asProduction(Closure $run): mixed
{
    $env = app()['env'];
    app()['env'] = 'production';
    try {
        return $run();
    } finally {
        app()['env'] = $env;
    }
}

it('tells crawlers what to skip without naming the admin path, with switchable AI groups', function () {
    $robots = asProduction(fn () => $this->get('/robots.txt')->assertOk()->getContent());

    expect($robots)
        ->not->toContain('/'.trim((string) config('statementra.security.admin_path', 'admin'), '/'))
        ->toContain("User-agent: *\nAllow: /\nDisallow: /o/")
        ->toContain('User-agent: OAI-SearchBot')
        ->toContain('User-agent: GPTBot')
        ->toContain('Sitemap: '.route('sitemap'))
        // Pages that carry noindex must stay crawlable so engines can read it.
        ->not->toContain('Disallow: /start')
        ->not->toContain('Disallow: /find-my-order');
    expect(substr_count($robots, 'Disallow: /checkout/'))->toBe(3); // repeated in every named group

    Settings::set('seo.ai_training_crawlers', false);
    $robots = asProduction(fn () => $this->get('/robots.txt')->getContent());
    expect($robots)->toMatch('~User-agent: Bytespider\nDisallow: /\n~')
        ->toMatch('~User-agent: Applebot\nAllow: /~');

    // Outside production nothing is indexed at all.
    expect($this->get('/robots.txt')->getContent())->toBe("User-agent: *\nDisallow: /\n");
});

it('lists every indexable page with real dates and article images, and leaves out thin categories', function () {
    seedSite();

    $xml = simplexml_load_string($this->get('/sitemap.xml')->assertOk()->getContent());
    $entries = [];
    foreach ($xml->url as $url) { // collect() would key every element as "url" and keep only the last
        $entries[] = $url;
    }
    $entries = collect($entries);
    $locs = $entries->map(fn ($url) => (string) $url->loc);
    $article = Article::query()->published()->whereNotNull('cover_image_path')->firstOrFail();

    expect($locs)->toContain(url('/'), route('services.index'), route('services.show', 'personal-statement'), route('pages.show', 'privacy-policy'), route('resources.show', $article->slug))
        ->and($locs->filter(fn ($loc) => str_contains($loc, '/resources/category/')))->toBeEmpty()
        ->and($entries->every(fn ($url) => (string) $url->lastmod !== ''))->toBeTrue();

    $entry = $entries->first(fn ($url) => (string) $url->loc === route('resources.show', $article->slug));
    expect((string) $entry->children('http://www.google.com/schemas/sitemap-image/1.1')->image->loc)->toStartWith('http');

    // A thin category stays out of search until it has enough guides.
    $category = ArticleCategory::query()->firstOrFail();
    $robotsOf = fn () => asProduction(fn () => $this->get(route('resources.category', $category->slug))->assertOk()->getContent());
    expect($robotsOf())->toContain('<meta name="robots" content="noindex, nofollow">');

    foreach (range(1, 3) as $i) {
        Article::query()->create(['title' => "Guide {$i}", 'slug' => "guide-{$i}", 'excerpt' => 'An excerpt.', 'body' => 'Body.', 'article_category_id' => $category->id, 'is_published' => true, 'published_at' => now()->subDay()]);
    }
    expect($this->get('/sitemap.xml')->getContent())->toContain(route('resources.category', $category->slug))
        ->and($robotsOf())->toContain('<meta name="robots" content="index, follow">');
});

it('describes the site for AI assistants in llms.txt, with live prices', function () {
    seedSite();
    Service::query()->where('slug', 'general-essay')->update(['is_active' => false]);

    $response = $this->get('/llms.txt')->assertOk();
    $text = $response->getContent();
    $service = Service::query()->where('slug', 'personal-statement')->firstOrFail();

    expect($response->headers->get('Content-Type'))->toStartWith('text/markdown')
        ->and($text)->toStartWith('# Statementra')
        ->toContain('['.$service->name.']('.route('services.show', $service->slug).')')
        ->toContain('Price: GH₵')
        ->toContain('## FAQ')
        ->toContain('Support: '.Settings::supportEmail())
        ->not->toContain(route('services.show', 'general-essay'));
});

it('publishes an Atom feed of the latest guides and a web manifest', function () {
    seedSite();

    $feed = simplexml_load_string($this->get(route('resources.feed'))->assertOk()->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8')->getContent());
    expect(count($feed->entry))->toBe(Article::query()->published()->count())
        ->and((string) $feed->entry[0]->id)->toStartWith(route('resources.index').'/');

    $this->get(route('manifest'))->assertOk()->assertJsonPath('name', 'Statementra')->assertJsonPath('icons.1.sizes', '512x512');
    $this->get('/')->assertSee(route('resources.feed'), false)->assertSee(route('manifest'), false);
});

it('serves the IndexNow key file only for the right key', function () {
    $key = app(IndexNow::class)->key();

    expect($key)->toMatch('/^[a-f0-9]{32}$/');
    $this->get('/'.$key.'.txt')->assertOk()->assertSeeText($key);
    $this->get('/'.str_repeat('a', 32).'.txt')->assertNotFound();
});

it('announces new and changed pages to IndexNow after the change, and only real changes', function () {
    config(['statementra.seo.indexnow_outside_production' => true]);
    Http::fake(['api.indexnow.org/*' => Http::response('', 202)]);
    seedSite(); // seeding saves content too, and announces it
    DB::table('search_pings')->delete();
    $before = count(Http::recorded());

    $article = Article::query()->published()->with('category')->firstOrFail();
    $article->update(['title' => 'A new title']);

    $requests = collect(Http::recorded())->slice($before)->map(fn (array $pair) => $pair[0])->values();
    expect($requests)->toHaveCount(1);
    /** @var HttpRequest $request */
    $request = $requests[0];
    expect($request->url())->toBe(IndexNow::ENDPOINT)
        ->and($request['host'])->toBe(parse_url(config('app.url'), PHP_URL_HOST))
        ->and($request['key'])->toBe(app(IndexNow::class)->key())
        ->and($request['keyLocation'])->toBe(route('indexnow.key', ['key' => app(IndexNow::class)->key()]))
        ->and($request['urlList'])->toContain(route('resources.show', $article->slug), route('resources.index'));
    expect(DB::table('search_pings')->where('url', route('resources.show', $article->slug))->value('status'))->toBe('sent');

    // Re-ordering is not a content change.
    DB::table('search_pings')->delete();
    $article->update(['display_order' => 7]);
    expect(DB::table('search_pings')->count())->toBe(0);
});

it('retries IndexNow later when the service is busy, and waits for scheduled articles', function () {
    config(['statementra.seo.indexnow_outside_production' => true]);
    Http::fake(['api.indexnow.org/*' => Http::response('slow down', 429)]);
    $this->seed(ContentSeeder::class);

    Article::query()->create(['title' => 'Now', 'slug' => 'now', 'excerpt' => 'x', 'body' => 'x', 'is_published' => true, 'published_at' => now()->subMinute()]);
    $row = DB::table('search_pings')->where('url', route('resources.show', 'now'))->first();
    expect($row->status)->toBe('pending')->and($row->attempts)->toBe(1)->and($row->due_at)->not->toBeNull();

    Article::query()->create(['title' => 'Later', 'slug' => 'later', 'excerpt' => 'x', 'body' => 'x', 'is_published' => true, 'published_at' => now()->addDays(2)]);
    $later = DB::table('search_pings')->where('url', route('resources.show', 'later'))->first();
    expect($later->status)->toBe('pending')->and($later->attempts)->toBe(0)
        ->and(substr((string) $later->due_at, 0, 10))->toBe(now()->addDays(2)->utc()->toDateString());
});

it('sends one URL per page: other spellings redirect, and pages past the end do not exist', function () {
    seedSite();

    $this->get('/services/PERSONAL-STATEMENT')->assertRedirect(route('services.show', 'personal-statement'))->assertStatus(301);
    $this->get('/resources?page=99')->assertNotFound();
});

it('sends signed-out visitors from their account page to the sign-in page', function () {
    $this->get('/account')->assertRedirect(route('account.login'));
});

it('describes pages for search and social: locale, image size, organisation and offers', function () {
    seedSite();

    $home = $this->get('/')->assertOk()->getContent();
    expect($home)
        ->toContain('<meta property="og:locale" content="en_GB">')
        ->toContain('<meta property="og:image" content="'.asset('images/brand/statementra-social.jpg').'">')
        ->toContain('<meta property="og:image:width" content="1200">')
        ->toContain('"@id":"'.url('/').'#organization"')
        ->toContain(asset('icon-512.png'));

    $service = $this->get(route('services.show', 'personal-statement'))->getContent();
    preg_match_all('~<script type="application/ld\+json"[^>]*>(.*?)</script>~s', $service, $blocks);
    $offer = collect($blocks[1])->map(fn ($json) => json_decode($json, true))->firstWhere('@type', 'Service')['offers'];
    expect($offer['url'])->toBe(route('services.show', 'personal-statement'))
        ->and($offer['priceCurrency'])->toBe('GHS')
        ->and($offer['seller']['@id'])->toBe(url('/').'#organization');

    $article = Article::query()->published()->firstOrFail();
    $this->get(route('resources.show', $article->slug))->assertSee('<meta property="article:published_time"', false);
});

it('keeps every title within 60 characters by dropping the site suffix when needed', function () {
    seedSite();

    foreach (['/', '/services', '/how-it-works', '/about', '/contact', '/privacy-policy', route('services.show', 'personal-statement'), route('resources.show', Article::query()->published()->value('slug'))] as $url) {
        preg_match('~<title>(.*?)</title>~s', $this->get($url)->getContent(), $title);
        expect(mb_strlen(html_entity_decode($title[1], ENT_QUOTES)))->toBeLessThanOrEqual(60, $url);
    }
});

it('keeps admin and private pages out of search with a header', function () {
    $this->get('/admin/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect(SeoController::AI_SEARCH_AGENTS)->toContain('OAI-SearchBot');
});
