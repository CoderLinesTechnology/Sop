<?php

namespace App\Domain\Catalogue;

use App\Domain\Pricing\PriceCalculator;
use App\Domain\Pricing\Quote;
use App\Models\Article;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Service;
use App\Models\Testimonial;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Read model for the public site. Services and their prices always come from
 * the database (never hard-coded), and prices are computed by the server's
 * PriceCalculator at render time so promotions start and stop on schedule.
 *
 * Cached reads store raw attribute arrays, never serialized objects: the cache
 * store refuses to unserialize classes (cache.serializable_classes = false),
 * which closes off PHP object-injection through a poisoned cache entry.
 */
class Catalogue
{
    public const CACHE_TAG_PREFIX = 'site:';

    public function __construct(private readonly PriceCalculator $prices) {}

    /** @return Collection<int, array{service: Service, quote: Quote}> */
    public function services(): Collection
    {
        $services = $this->rememberModels(self::CACHE_TAG_PREFIX.'services', Service::class, fn () => Service::query()
            ->active()->ordered()->get());

        return $services->map(fn (Service $service) => [
            'service' => $service,
            'quote' => $this->prices->quote($service),
        ]);
    }

    public function service(string $slug): ?Service
    {
        return Service::query()->active()->where('slug', $slug)->with(['faqs' => fn ($q) => $q->published()])->first();
    }

    public function quote(Service $service): Quote
    {
        return $this->prices->quote($service);
    }

    public function page(string $slug): ?Page
    {
        $attributes = Cache::remember(self::CACHE_TAG_PREFIX.'page:'.$slug, now()->addMinutes(10), fn () => Page::query()
            ->published()->where('slug', $slug)->first()?->getAttributes());

        return is_array($attributes) ? (new Page)->newFromBuilder($attributes) : null;
    }

    /** @return Collection<int, Faq> */
    public function faqs(string $scope, int $limit = 50): Collection
    {
        return $this->rememberModels(self::CACHE_TAG_PREFIX."faqs:{$scope}:{$limit}", Faq::class, fn () => Faq::query()
            ->where('scope', $scope)->published()->limit($limit)->get());
    }

    /** @return Collection<int, Testimonial> */
    public function testimonials(int $limit = 3): Collection
    {
        return $this->rememberModels(self::CACHE_TAG_PREFIX."testimonials:{$limit}", Testimonial::class, fn () => Testimonial::query()
            ->published()->orderByDesc('is_featured')->limit($limit)->get());
    }

    /** @return Collection<int, Article> */
    public function featuredArticles(int $limit = 4): Collection
    {
        return $this->rememberModels(self::CACHE_TAG_PREFIX."articles:featured:{$limit}", Article::class, fn () => Article::query()
            ->published()->with('category')
            ->orderByDesc('is_featured')->orderBy('display_order')->latest('published_at')
            ->limit($limit)->get(), ['category']);
    }

    /**
     * Cache a query's models as raw attribute arrays and rehydrate them on read.
     * Only to-one relations listed in $relations are carried through the cache.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  Closure(): EloquentCollection<int, TModel>  $query
     * @param  list<string>  $relations
     * @return EloquentCollection<int, TModel>
     */
    private function rememberModels(string $key, string $model, Closure $query, array $relations = []): EloquentCollection
    {
        $rows = Cache::remember($key, now()->addMinutes(10), fn () => $query()
            ->map(fn (Model $item) => [
                'attributes' => $item->getAttributes(),
                'relations' => collect($relations)->mapWithKeys(fn (string $relation) => [
                    $relation => $item->getRelation($relation)?->getAttributes(),
                ])->all(),
            ])->all());

        $prototype = new $model;

        return $prototype->newCollection(array_map(function (array $row) use ($prototype) {
            $instance = $prototype->newFromBuilder($row['attributes']);
            foreach ($row['relations'] as $relation => $attributes) {
                $instance->setRelation($relation, $attributes === null
                    ? null
                    : $instance->{$relation}()->getRelated()->newFromBuilder($attributes));
            }

            return $instance;
        }, $rows));
    }

    public static function forgetPage(string $slug): void
    {
        Cache::forget(self::CACHE_TAG_PREFIX.'page:'.$slug);
    }

    /** Forget every cached public read model (called when admins change content). */
    public static function flush(): void
    {
        foreach (['services', 'footer-services'] as $key) {
            Cache::forget(self::CACHE_TAG_PREFIX.$key);
        }
        foreach (['home', 'services', 'resources', 'how-it-works', 'about', 'faq', 'contact', 'privacy-policy', 'terms', 'refund-policy', 'cookie-policy'] as $page) {
            Cache::forget(self::CACHE_TAG_PREFIX.'page:'.$page);
        }
        foreach (['general', 'home', 'resources', 'service'] as $scope) {
            foreach ([5, 6, 10, 50] as $limit) {
                Cache::forget(self::CACHE_TAG_PREFIX."faqs:{$scope}:{$limit}");
            }
        }
        foreach ([3, 4, 6, 8] as $limit) {
            Cache::forget(self::CACHE_TAG_PREFIX."testimonials:{$limit}");
            Cache::forget(self::CACHE_TAG_PREFIX."articles:featured:{$limit}");
        }
        Cache::forget(self::CACHE_TAG_PREFIX.'sitemap');
    }
}
