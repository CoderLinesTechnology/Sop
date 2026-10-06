<?php

namespace App\Http\Controllers\Site;

use App\Domain\Catalogue\Catalogue;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Support\Seo;
use App\Support\Settings;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ArticleController extends Controller
{
    public function index(Catalogue $catalogue): View
    {
        return $this->listing($catalogue, null);
    }

    public function category(Catalogue $catalogue, string $slug): View
    {
        $category = ArticleCategory::query()->where('slug', $slug)->where('is_active', true)->first();
        abort_if($category === null, 404);

        return $this->listing($catalogue, $category);
    }

    public function show(Catalogue $catalogue, string $slug): View
    {
        $article = Article::query()->published()->with('category')->where('slug', $slug)->first();
        abort_if($article === null, 404);

        $related = Article::query()->published()
            ->whereKeyNot($article->id)
            ->when($article->article_category_id, fn ($q) => $q->orderByRaw('article_category_id = ? desc', [$article->article_category_id]))
            ->latest('published_at')->limit(3)->get();

        $seo = Seo::make($article->seo_title ?: $article->title, $article->seo_description ?: $article->excerpt)
            ->withBreadcrumbs([['Home', url('/')], ['Resources', route('resources.index')], [$article->title, route('resources.show', $article->slug)]])
            ->withJsonLd(array_filter([
                '@type' => 'Article',
                'headline' => $article->title,
                'description' => $article->excerpt,
                'image' => $article->cover_image_path ? (\App\Support\ResponsiveImage::resolve($article->cover_image_path)['src'] ?? null) : null,
                'datePublished' => $article->published_at?->toIso8601String(),
                'dateModified' => $article->updated_at?->toIso8601String(),
                'author' => ['@type' => 'Organization', 'name' => $article->author_name ?: Settings::siteName()],
                'publisher' => ['@type' => 'Organization', 'name' => Settings::siteName(), 'logo' => ['@type' => 'ImageObject', 'url' => asset('favicon.svg')]],
                'mainEntityOfPage' => route('resources.show', $article->slug),
            ]));
        $seo->type = 'article';
        $seo->image = $article->cover_image_path;

        return view('site.resources.show', [
            'article' => $article,
            'html' => Str::markdown((string) $article->body, ['html_input' => 'strip', 'allow_unsafe_links' => false], [new \League\CommonMark\Extension\Table\TableExtension]),
            'related' => $related,
            'seo' => $seo,
        ]);
    }

    private function listing(Catalogue $catalogue, ?ArticleCategory $category): View
    {
        $page = $catalogue->page('resources');

        $articles = Article::query()->published()->with('category')
            ->when($category, fn ($q) => $q->where('article_category_id', $category->id))
            ->orderByDesc('is_featured')->orderBy('display_order')->latest('published_at')
            ->paginate(12);

        $title = $category ? $category->name.' guides' : ($page?->seo_title ?: 'Resources');
        $faqs = $catalogue->faqs('resources', 5);

        $seo = Seo::make($title, $category?->description ?: $page?->seo_description)
            ->withBreadcrumbs(array_filter([
                ['Home', url('/')],
                ['Resources', route('resources.index')],
                $category ? [$category->name, route('resources.category', $category->slug)] : null,
            ]))
            ->withFaqs($category ? [] : $faqs);

        return view('site.resources.index', [
            'page' => $page,
            'category' => $category,
            'categories' => ArticleCategory::query()->active()->withCount(['articles' => fn ($q) => $q->published()])->get()->filter(fn ($c) => $c->articles_count > 0),
            'articles' => $articles,
            'faqs' => $faqs,
            'seo' => $seo,
        ]);
    }
}
