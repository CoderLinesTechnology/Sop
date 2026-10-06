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
