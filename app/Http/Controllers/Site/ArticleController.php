<?php

namespace App\Http\Controllers\Site;

use App\Domain\Catalogue\Catalogue;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Support\ResponsiveImage;
use App\Support\Seo;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;
use League\CommonMark\Extension\Table\TableExtension;

class ArticleController extends Controller
{
    /** Categories with fewer published articles are thin pages: shown to visitors, kept out of search. */
    public const MIN_INDEXABLE_CATEGORY_ARTICLES = 3;

    public function index(Catalogue $catalogue): View
    {
        return $this->listing($catalogue, null);
    }

    public function category(Catalogue $catalogue, string $slug): View|RedirectResponse
    {
        $category = ArticleCategory::query()->where('slug', $slug)->where('is_active', true)->first();
        abort_if($category === null, 404);

        if ($slug !== $category->slug) {
            return redirect()->route('resources.category', $category->slug, 301);
        }

        return $this->listing($catalogue, $category);
    }

    public function show(Catalogue $catalogue, string $slug): View|RedirectResponse
    {
        $article = Article::query()->published()->with('category')->where('slug', $slug)->first();
        abort_if($article === null, 404);

        if ($slug !== $article->slug) {
            return redirect()->route('resources.show', $article->slug, 301);
        }

        $related = Article::query()->published()
            ->whereKeyNot($article->id)
            ->when($article->article_category_id, fn ($q) => $q->orderByRaw('article_category_id = ? desc', [$article->article_category_id]))
            ->latest('published_at')->limit(3)->get();

        $published = ($article->published_at ?? $article->created_at)?->toIso8601String();
        $modified = $article->updated_at?->toIso8601String();
        $seo = Seo::make($article->seo_title ?: $article->title, $article->seo_description ?: $article->excerpt)
            ->withBreadcrumbs([['Home', url('/')], ['Resources', route('resources.index')], [$article->title, route('resources.show', $article->slug)]])
            ->withJsonLd(array_filter([
                '@type' => 'Article',
                'headline' => $article->title,
                'description' => $article->excerpt,
                'image' => $article->cover_image_path ? (ResponsiveImage::resolve($article->cover_image_path)['src'] ?? null) : asset(Seo::DEFAULT_IMAGE),
                'datePublished' => $published,
                'dateModified' => $modified,
                'inLanguage' => 'en',
                'articleSection' => $article->category?->name,
                'author' => ['@type' => 'Organization', 'name' => $article->author_name ?: Settings::siteName(), 'url' => url('/')],
                'publisher' => Seo::organizationRef() + ['logo' => ['@type' => 'ImageObject', 'url' => asset('icon-512.png'), 'width' => 512, 'height' => 512]],
                'mainEntityOfPage' => route('resources.show', $article->slug),
            ]));
        $seo->type = 'article';
        $seo->image = $article->cover_image_path;
        $seo->publishedTime = $published;
        $seo->modifiedTime = $modified;

        return view('site.resources.show', [
            'article' => $article,
            'html' => Str::markdown((string) $article->body, ['html_input' => 'strip', 'allow_unsafe_links' => false], [new TableExtension]),
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

        // Pages past the end do not exist (an empty listing would be a soft 404).
        abort_if($articles->currentPage() > max(1, $articles->lastPage()), 404);

        $title = $category ? $category->name.': Writing Guides and Tips' : ($page?->seo_title ?: 'Resources');
        $description = $category
            ? ($category->description ?: 'Practical guides and tips on '.mb_strtolower($category->name).' from '.Settings::siteName().'.')
            : $page?->seo_description;
        if ($articles->currentPage() > 1) {
            $title .= ' (Page '.$articles->currentPage().')';
        }
        $faqs = $catalogue->faqs('resources', 5);

        $seo = Seo::make($title, $description, index: $category === null || $articles->total() >= self::MIN_INDEXABLE_CATEGORY_ARTICLES)
            ->withBreadcrumbs(array_filter([
                ['Home', url('/')],
                ['Resources', route('resources.index')],
                $category ? [$category->name, route('resources.category', $category->slug)] : null,
            ]))
            ->withFaqs($category || $articles->currentPage() > 1 ? [] : $faqs);
        // Each page of a listing is its own canonical page; the first page has no ?page=.
        $seo->canonical = $articles->currentPage() > 1 ? $articles->url($articles->currentPage()) : url()->current();

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
