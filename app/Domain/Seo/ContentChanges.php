<?php

namespace App\Domain\Seo;

use App\Http\Controllers\Site\PageController;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Service;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps an admin change to the public pages it affects and hands them to
 * IndexNow. Registered as model events in AppServiceProvider. Changes that
 * do not alter a page (ordering, featured flags) are not announced.
 */
final class ContentChanges
{
    private const IGNORED = ['updated_at', 'display_order', 'is_featured', 'sort_order'];

    public static function register(): void
    {
        foreach ([Service::class, Article::class, Page::class, ArticleCategory::class, Faq::class] as $model) {
            $model::saved(fn (Model $record) => self::changed($record));
            $model::deleted(fn (Model $record) => self::changed($record, deleted: true));
        }
    }

    public static function changed(Model $record, bool $deleted = false): void
    {
        if (! $deleted && ! $record->wasRecentlyCreated && array_diff(array_keys($record->getChanges()), self::IGNORED) === []) {
            return;
        }

        $indexNow = app(IndexNow::class);
        if (! $indexNow->enabled()) {
            return;
        }

        [$urls, $notBefore] = match (true) {
            $record instanceof Service => [self::serviceUrls($record), null],
            $record instanceof Article => self::articleUrls($record),
            $record instanceof Page => [self::pageUrls($record), null],
            $record instanceof ArticleCategory => [[route('resources.category', $record->slug), route('resources.index')], null],
            $record instanceof Faq => [[route('pages.faq')], null],
            default => [[], null],
        };

        if ($urls !== []) {
            $indexNow->queue($urls, $notBefore);
        }
    }

    /** @return list<string> */
    private static function serviceUrls(Service $service): array
    {
        // Pages of a service that was never public are not announced.
        if (! $service->is_active && ! $service->getOriginal('is_active')) {
            return [];
        }

        return [route('services.show', $service->slug), route('services.index'), url('/')];
    }

    /** @return array{0:list<string>, 1:?CarbonInterface} */
    private static function articleUrls(Article $article): array
    {
        if (! $article->is_published && ! $article->getOriginal('is_published')) {
            return [[], null];
        }

        $urls = [route('resources.show', $article->slug), route('resources.index')];
        if ($article->article_category_id && ($slug = ArticleCategory::query()->whereKey($article->article_category_id)->value('slug'))) {
            $urls[] = route('resources.category', $slug);
        }

        // A scheduled article is announced when it goes live (the heartbeat sends it then).
        $notBefore = $article->is_published && $article->published_at?->isFuture() ? $article->published_at : null;

        return [$urls, $notBefore];
    }

    /** @return list<string> */
    private static function pageUrls(Page $page): array
    {
        if (! $page->is_published && ! $page->getOriginal('is_published')) {
            return [];
        }

        return array_values(array_filter([match ($page->slug) {
            'home' => url('/'),
            'services' => route('services.index'),
            'resources' => route('resources.index'),
            'how-it-works' => route('pages.how-it-works'),
            'faq' => route('pages.faq'),
            'contact' => route('contact.show'),
            default => in_array($page->slug, PageController::STANDARD_PAGES, true) ? route('pages.show', $page->slug) : null,
        }]));
    }
}
