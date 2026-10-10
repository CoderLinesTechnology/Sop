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
    /** Titles longer than this are shown without the site-name suffix (search results cut them off). */
    public const MAX_TITLE = 60;

    /** The default social image (public/images/brand), 1200x630 as social networks expect. */
    public const DEFAULT_IMAGE = 'images/brand/statementra-social.jpg';

    public const DEFAULT_IMAGE_SIZE = [1200, 630];

    /** @param list<array<string, mixed>> $jsonLd */
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?string $canonical = null,
        public ?string $image = null,
        public bool $index = true,
        public array $jsonLd = [],
        public string $type = 'website',
        public ?string $publishedTime = null,
        public ?string $modifiedTime = null,
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

        $full = $this->title.Settings::get('seo.title_suffix', ' | '.$site);

        return mb_strlen($full) <= self::MAX_TITLE ? $full : $this->title;
    }

    public function locale(): string
    {
        return (string) Settings::get('seo.og_locale', 'en_GB');
    }

    /** Width and height of the social image, when it is the default one (other sizes are unknown). */
    public function imageSize(): ?array
    {
        return ($this->image ?? Settings::get('seo.social_image')) ? null : self::DEFAULT_IMAGE_SIZE;
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
            return asset(self::DEFAULT_IMAGE);
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
            '@id' => self::organizationId(),
            'name' => Settings::siteName(),
            'url' => url('/'),
            // Search engines want a raster logo of at least 112x112 px.
            'logo' => ['@type' => 'ImageObject', 'url' => asset('icon-512.png'), 'width' => 512, 'height' => 512],
            'description' => Settings::get('general.tagline') ?: null,
            'email' => Settings::supportEmail(),
            'contactPoint' => [[
                '@type' => 'ContactPoint',
                'contactType' => 'customer support',
                'email' => Settings::supportEmail(),
                'availableLanguage' => ['English'],
            ]],
            'sameAs' => $socials ?: null,
        ]);
    }

    public static function organizationId(): string
    {
        return url('/').'#organization';
    }

    /** A reference to the Organization for other structured data (provider, publisher, seller). */
    public static function organizationRef(): array
    {
        return ['@type' => 'Organization', '@id' => self::organizationId(), 'name' => Settings::siteName(), 'url' => url('/')];
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
