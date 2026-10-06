<?php

namespace App\Filament\Support\Catalogue;

use App\Models\Page;
use App\Models\Service;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;

/**
 * Editable sections of the structured landing pages (see ContentSeeder for
 * the shapes). Each page slug has a known set of sections and keys; forms
 * edit those keys and every other key stored in `sections` is preserved.
 */
final class PageSections
{
    /** Pages the site depends on: their slugs cannot change and they cannot be deleted. */
    public const SYSTEM_PAGES = [
        'home', 'services', 'resources', 'how-it-works', 'about', 'faq', 'contact',
        'privacy-policy', 'terms', 'refund-policy', 'cookie-policy',
    ];

    public const KINDS = [
        'standard' => 'Standard page (Markdown body)',
        'legal' => 'Legal page (Markdown body)',
        'landing' => 'Landing page (sections)',
        'home' => 'Home page (sections)',
    ];

    private const HEADING = ['eyebrow' => 'text', 'title' => 'title', 'text' => 'textarea'];

    /**
     * section key => [label, description, fields (key => type)].
     * Types: text, title (multi-line), textarea, image, cta, steps, features, points, details, service.
     */
    private const DEFINITIONS = [
        'home' => [
            'hero' => ['Hero', 'The first screen of the home page.', ['title' => 'title', 'text' => 'textarea', 'primary_cta' => 'cta', 'secondary_cta' => 'cta', 'image' => 'image', 'image_alt' => 'alt', 'image_caption' => 'textarea', 'trust_note' => 'text']],
            'services' => ['Services', 'Introduces the service cards.', self::HEADING],
            'how_it_works' => ['How it works', 'The numbered steps.', self::HEADING + ['steps' => 'steps']],
            'research' => ['Research process', 'Image with a checklist of research points.', self::HEADING + ['image' => 'image', 'image_alt' => 'alt', 'image_caption' => 'textarea', 'points' => 'points']],
            'offer' => ['Special offer', 'Highlights one service and its current price.', self::HEADING + ['service_slug' => 'service', 'tagline' => 'text']],
            'testimonials' => ['Testimonials', 'Heading above customer testimonials (managed under Content → Testimonials).', self::HEADING],
            'resources' => ['Resources', 'Heading above the featured guides.', self::HEADING],
            'faq' => ['FAQ', 'Heading above the home-page FAQs (Content → FAQs, scope “Home page”).', self::HEADING],
            'cta' => ['Closing call to action', null, ['title' => 'title', 'text' => 'textarea', 'button' => 'cta', 'image' => 'image']],
        ],
        'services' => [
            'hero' => ['Hero', null, self::HEADING + ['image' => 'image', 'image_alt' => 'alt', 'image_caption' => 'textarea']],
            'list' => ['Service list', 'Heading above the list of services.', self::HEADING],
            'why' => ['Why Statementra', null, self::HEADING + ['button' => 'cta', 'features' => 'features']],
        ],
        'resources' => [
            'hero' => ['Hero', null, self::HEADING + ['image' => 'image', 'image_alt' => 'alt']],
            'featured' => ['Featured guides', null, ['eyebrow' => 'text', 'title' => 'title']],
            'newsletter' => ['Newsletter', null, self::HEADING + ['note' => 'textarea']],
            'faq' => ['FAQ', 'Heading above the resources FAQs (Content → FAQs, scope “Resources page”).', self::HEADING],
            'cta' => ['Closing call to action', null, ['title' => 'title', 'text' => 'textarea', 'button' => 'cta', 'image' => 'image']],
        ],
        'how-it-works' => [
            'hero' => ['Hero', null, self::HEADING],
            'details' => ['Process details', 'Each step of the process, in order.', ['__list' => 'details']],
        ],
        'faq' => [
            'hero' => ['Hero', null, self::HEADING],
        ],
        'contact' => [
            'hero' => ['Hero', null, self::HEADING],
        ],
    ];

    /** Sections for landing pages created by administrators. */
    private const CUSTOM_LANDING = [
        'hero' => ['Hero', null, self::HEADING + ['image' => 'image', 'image_alt' => 'alt']],
    ];

    public static function isSystemPage(?string $slug): bool
    {
        return in_array((string) $slug, self::SYSTEM_PAGES, true);
    }

    public static function usesSections(?string $kind): bool
    {
        return in_array($kind, ['home', 'landing'], true);
    }

    /** @return array<string, array{0:string,1:?string,2:array<string,string>}> */
    public static function definitionFor(?string $slug, ?string $kind): array
    {
        if (! self::usesSections($kind)) {
            return [];
        }

        return self::DEFINITIONS[(string) $slug] ?? self::CUSTOM_LANDING;
    }

    /**
     * Form components for a page's sections (state path "sections.{section}.{key}").
     *
     * @return list<Component>
     */
    public static function components(?string $slug, ?string $kind): array
    {
        $components = [];

        foreach (self::definitionFor($slug, $kind) as $section => [$label, $description, $fields]) {
            $children = [];
            foreach ($fields as $key => $type) {
                $children[] = $key === '__list'
                    ? self::field("sections.{$section}", $type, $section)
                    : self::field("sections.{$section}.{$key}", $type, $section);
            }

            $components[] = Section::make($label)
                ->description($description)
                ->schema($children)
                ->columns(2)
                ->collapsible()
                ->key("section-{$section}");
        }

        return $components;
    }

    /**
     * Merge edited sections into the stored ones: edited keys replace stored
     * values (lists are replaced wholesale), unknown sections and keys are kept.
     *
     * @param  array<string, mixed>|null  $stored
     * @param  array<string, mixed>|null  $edited
     * @return array<string, mixed>
     */
    public static function merge(?array $stored, ?array $edited): array
    {
        $result = $stored ?? [];

        foreach ($edited ?? [] as $section => $data) {
            if (! is_array($data) || array_is_list($data) || ! is_array($result[$section] ?? null) || array_is_list($result[$section])) {
                $result[$section] = $data;

                continue;
            }

            foreach ($data as $key => $value) {
                $result[$section][$key] = $value;
            }
        }

        return $result;
    }

    private static function field(string $path, string $type, string $section): Component
    {
        $name = Str::afterLast($path, '.');
        $label = Str::of($name)->replace('_', ' ')->ucfirst()->toString();

        return match ($type) {
            'text' => TextInput::make($path)->label($label)->maxLength(255),
            'alt' => TextInput::make($path)->label('Image description (alt text)')->maxLength(255)
                ->helperText('Describe the image for screen readers and search engines.'),
            'cta' => TextInput::make($path)->label(match ($name) {
                'primary_cta' => 'Primary button',
                'secondary_cta' => 'Secondary button',
                default => 'Button label',
            })->maxLength(60),
            'title' => Textarea::make($path)->label('Title')->rows(2)->maxLength(255)
                ->helperText('Line breaks are kept.'),
            'textarea' => Textarea::make($path)->label($label === 'Image caption' ? 'Image caption' : ($label === 'Note' ? 'Note' : 'Text'))->rows(3)->maxLength(1000)->columnSpanFull(),
            'image' => MediaUpload::to($path, 'pages/'.$section)->label('Image')->columnSpanFull(),
            'service' => Select::make($path)
                ->label('Featured service')
                ->options(fn (?string $state): array => IconOptions::withCurrent(
                    Service::query()->active()->ordered()->pluck('name', 'slug')->all(),
                    $state,
                ))
                ->helperText('The service (and its current price) highlighted in the offer.')
                ->native(false),
            'points' => Repeater::make($path)
                ->label('Points')
                ->simple(TextInput::make('point')->required()->maxLength(120))
                ->addActionLabel('Add point')
                ->reorderableWithButtons()
                ->defaultItems(0)
                ->columnSpanFull(),
            'steps', 'features' => Repeater::make($path)
                ->label($type === 'steps' ? 'Steps' : 'Features')
                ->schema([
                    Select::make('icon')->options(fn (?string $state): array => IconOptions::withCurrent(IconOptions::CONTENT_ICONS, $state))->required()->native(false),
                    TextInput::make('title')->required()->maxLength(120),
                    Textarea::make('text')->rows(2)->maxLength(500)->columnSpanFull(),
                ])
                ->columns(2)
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->addActionLabel($type === 'steps' ? 'Add step' : 'Add feature')
                ->reorderableWithButtons()
                ->collapsible()
                ->defaultItems(0)
                ->columnSpanFull(),
            'details' => Repeater::make($path)
                ->hiddenLabel()
                ->schema([
                    TextInput::make('title')->required()->maxLength(120),
                    Textarea::make('text')->required()->rows(3)->maxLength(1500),
                ])
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->addActionLabel('Add step')
                ->reorderableWithButtons()
                ->collapsible()
                ->defaultItems(0)
                ->columnSpanFull(),
        };
    }
}
