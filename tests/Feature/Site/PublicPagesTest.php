<?php

use App\Models\Article;
use App\Models\Service;
use Database\Seeders\ArticleSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\ServiceCatalogSeeder;

$pages = ['/', '/services', '/how-it-works', '/resources', '/about', '/faq', '/contact', '/privacy-policy', '/terms',
    '/refund-policy', '/cookie-policy', '/find-my-order', '/account/login', '/start', '/sitemap.xml', '/robots.txt'];

beforeEach(fn () => config()->set('statementra.runtime.tasks', []));

it('never errors on a fresh database, before any content exists', function (string $path) {
    $response = $this->get($path);

    expect($response->status())->toBeIn([200, 404], $path.' => '.($response->exception?->getMessage() ?? ''));
})->with($pages);

it('renders every public page with the seeded catalogue and content', function (string $path) {
    $this->seed([ServiceCatalogSeeder::class, ContentSeeder::class, ArticleSeeder::class]);

    $this->get($path)->assertOk();
})->with($pages);

it('renders every service, article and category page', function () {
    $this->seed([ServiceCatalogSeeder::class, ContentSeeder::class, ArticleSeeder::class]);

    foreach (Service::query()->active()->pluck('slug') as $slug) {
        $this->get('/services/'.$slug)->assertOk();
        $this->get('/start/'.$slug)->assertOk();
    }
    foreach (Article::query()->published()->with('category')->get() as $article) {
        $this->get('/resources/'.$article->slug)->assertOk()->assertSee($article->title);
        if ($article->category) {
            $this->get('/resources/category/'.$article->category->slug)->assertOk();
        }
    }
});

it('shows "Find my order" in the desktop and the mobile menu, opening the find-my-order page', function () {
    $this->seed(ContentSeeder::class);
    $menus = fn (string $html, string $label) => [
        preg_match('/<nav aria-label="'.$label.'".*?<\/nav>/s', $html, $m) ? $m[0] : '',
    ][0];

    $home = $this->get('/')->assertOk()->getContent();
    foreach (['Main', 'Mobile'] as $menu) {
        expect($menus($home, $menu))->toContain('href="'.route('orders.lookup').'"')->toContain('Find my order')
            ->not->toContain(route('account.login'));
    }

    // Highlighted as the current page on the find-my-order page itself.
    expect($menus($this->get(route('orders.lookup'))->assertOk()->getContent(), 'Main'))
        ->toMatch('/href="'.preg_quote(route('orders.lookup'), '/').'"[^>]*aria-current="page"|aria-current="page"[^>]*>Find my order/s');
});
