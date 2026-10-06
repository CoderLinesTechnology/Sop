<?php

namespace App\Support;

use App\Models\Faq;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SEO metadata for a page: title, description, canonical URL, social image,
 * robots directive and structured data (JSON-LD).
 */
final class Seo
{
    /** @param list<array<string, mixed>> $jsonLd */
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?string $canonical = null,
        public ?string $image = null,
        public bool $index = true,
        public array $jsonLd = [],
        public string $type = 'website',
    ) {}

    public static function make(?string $title = null, ?string $description = null, bool $index = true): self
    {
        return new self(
            title: $title ?: (string) Settings::get('seo.default_title'),
            description: $description ?: (string) Settings::get('seo.default_description'),
            index: $index,
        );
    }

    public function fullTitle(): string
    {
        $site = Settings::siteName();
        if (str_contains($this->title, $site)) {
            return $this->title;
        }

        return $this->title.Settings::get('seo.title_suffix', ' | '.$site);
    }

    public function withJsonLd(array $data): self
    {
        $this->jsonLd[] = ['@context' => 'https://schema.org'] + $data;

        return $this;
    }

    public function canonicalUrl(): string
    {
        return $this->canonical ?? url()->current();
    }

    public function imageUrl(): ?string
    {
        $image = $this->image ?? Settings::get('seo.social_image');
        if (! $image) {
            return asset('images/home/hero.webp');
        }

        return str_starts_with($image, 'http') ? $image : (str_starts_with($image, 'images/') ? asset($image) : Storage::disk(config('statementra.storage.public_disk', 'public'))->url($image));
    }

    public static function organization(): array
    {
        $socials = array_values(array_filter([
            Settings::get('general.social_linkedin'),
            Settings::get('general.social_x'),
            Settings::get('general.social_instagram'),
            Settings::get('general.social_youtube'),
        ]));

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => Settings::siteName(),
            'url' => url('/'),
            'logo' => asset('favicon.svg'),
            'email' => Settings::supportEmail(),
            'sameAs' => $socials ?: null,
        ]);
    }

    /** @param list<array{0:string,1:string}> $crumbs [label, url] */
    public function withBreadcrumbs(array $crumbs): self
    {
        return $this->withJsonLd([
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn ($crumb, $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb[0],
                'item' => $crumb[1],
            ], $crumbs, array_keys($crumbs)),
        ]);
    }

    /** @param iterable<Faq> $faqs */
    public function withFaqs(iterable $faqs): self
    {
        $items = [];
        foreach ($faqs as $faq) {
            $items[] = [
                '@type' => 'Question',
                'name' => $faq->question,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags((string) Str::markdown($faq->answer, ['html_input' => 'strip']))],
            ];
        }

        return $items ? $this->withJsonLd(['@type' => 'FAQPage', 'mainEntity' => $items]) : $this;
    }
}
