@php
    $hero = $page?->section('hero') ?? [];
    $featured = $page?->section('featured') ?? [];
    $newsletter = $page?->section('newsletter') ?? [];
    $faqSection = $page?->section('faq') ?? [];
    $cta = $page?->section('cta') ?? [];
@endphp
<x-layouts.site :seo="$seo">
    {{-- Hero --}}
    <section class="relative overflow-hidden border-b border-line bg-[#f7f5f1]">
        <div class="container-site grid items-center gap-10 py-12 lg:grid-cols-[1fr_1.05fr] lg:gap-0 lg:py-0">
            <div class="relative z-10 lg:py-20 lg:pr-12">
                <p class="eyebrow">{{ $hero['eyebrow'] ?? 'Resources' }}</p>
                <h1 class="display-1 mt-4 max-w-xl">{{ $category ? $category->name.' guides' : ($hero['title'] ?? 'Guides, tips and resources to help you succeed.') }}</h1>
                <p class="lead mt-6 max-w-lg">{{ $category?->description ?: ($hero['text'] ?? '') }}</p>
            </div>
            <div class="relative -mx-4 sm:mx-0 lg:-mr-[max(2rem,calc((100vw-1200px)/2+2rem))] lg:h-full">
                <x-picture :path="$hero['image'] ?? 'images/resources/hero.jpg'" :alt="$hero['image_alt'] ?? ''" :eager="true" sizes="(min-width: 1024px) 55vw, 100vw"
                           img-class="h-full max-h-[420px] w-full object-cover lg:max-h-none lg:min-h-[400px]" class="block h-full" />
                <div class="pointer-events-none absolute inset-y-0 left-0 hidden w-40 bg-gradient-to-r from-[#f7f5f1] to-transparent lg:block"></div>
            </div>
        </div>
    </section>

    {{-- Category tabs --}}
    <nav aria-label="Guide categories" class="border-b border-line bg-white">
        <div class="container-site">
            <ul class="-mb-px flex gap-1 overflow-x-auto [scrollbar-width:none] sm:justify-between">
                @php($tabs = collect([['name' => 'All Guides', 'slug' => null, 'icon' => 'book']])->concat($categories->map(fn ($c) => ['name' => $c->name, 'slug' => $c->slug, 'icon' => $c->icon])))
                @foreach ($tabs as $tab)
                    @php($active = $tab['slug'] === $category?->slug)
                    <li class="shrink-0">
                        <a href="{{ $tab['slug'] ? route('resources.category', $tab['slug']) : route('resources.index') }}"
                           @class(['flex flex-col items-center gap-2 border-b-2 px-4 py-5 text-[0.8rem] font-medium whitespace-nowrap transition sm:px-5',
                               'border-brand-700 text-ink' => $active, 'border-transparent text-body hover:text-brand-700' => ! $active])
                           @if ($active) aria-current="page" @endif>
                            <x-icon :name="$tab['icon']" class="size-6" stroke="1.5" />
                            {{ $tab['name'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </nav>

    {{-- Articles --}}
    <section class="py-14 sm:py-16" aria-labelledby="articles-heading">
        <div class="container-site">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="eyebrow">{{ $category ? $category->name : ($featured['eyebrow'] ?? 'Featured guides') }}</p>
                    <h2 id="articles-heading" class="display-2 mt-3">{{ $category ? 'Latest guides' : ($featured['title'] ?? 'Popular resources') }}</h2>
                </div>
                @if ($category)
                    <a href="{{ route('resources.index') }}" class="link inline-flex items-center gap-1.5 text-sm">View all resources <x-icon name="arrow-right" class="size-4" /></a>
                @endif
            </div>

            @if ($articles->isNotEmpty())
                <div class="mt-9 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($articles as $article)
                        <x-marketing.article-card :article="$article" />
                    @endforeach
                </div>
                <div class="mt-10">{{ $articles->onEachSide(1)->links() }}</div>
            @else
                <p class="mt-9 rounded-[10px] border border-dashed border-line-strong bg-white p-8 text-center text-muted">No guides here yet — check back soon.</p>
            @endif
        </div>
    </section>

    <x-marketing.newsletter-band :section="$newsletter" />

    {{-- FAQ --}}
    @if ($faqs->isNotEmpty())
        <section class="py-16 sm:py-20" aria-labelledby="resources-faq-heading">
            <div class="container-site grid gap-10 lg:grid-cols-[1fr_1.3fr] lg:gap-16">
                <div>
                    <p class="eyebrow">{{ $faqSection['eyebrow'] ?? 'Frequently asked questions' }}</p>
                    <h2 id="resources-faq-heading" class="display-2 mt-3">{{ $faqSection['title'] ?? 'Still have questions?' }}</h2>
                    <p class="mt-4 max-w-md text-[0.95rem] text-body">{{ $faqSection['text'] ?? '' }}</p>
                    <a href="{{ route('pages.faq') }}" class="btn-outline mt-7">View all FAQs <x-icon name="arrow-right" class="size-4" /></a>
                </div>
                <x-ui.faq-list :faqs="$faqs" :compact="true" />
            </div>
        </section>
    @endif

    <x-marketing.cta-band :title="$cta['title'] ?? 'Ready to tell your story?'" :text="$cta['text'] ?? null" :button="$cta['button'] ?? 'Start Your Application'" :image="$cta['image'] ?? 'images/resources/cta.jpg'" />
</x-layouts.site>
