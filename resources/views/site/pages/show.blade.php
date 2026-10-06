<x-layouts.site :seo="$seo">
    <header class="border-b border-line bg-[#f7f5f1]">
        <div class="container-site max-w-3xl py-12 sm:py-16">
            @if ($page->kind === 'legal')
                <p class="eyebrow">Legal</p>
            @endif
            <h1 class="display-1 mt-3">{{ $page->title }}</h1>
            @if ($page->excerpt)
                <p class="lead mt-5">{{ $page->excerpt }}</p>
            @endif
        </div>
    </header>
    <div class="container-site max-w-3xl py-12 sm:py-16">
        <div class="prose-st">{!! $html !!}</div>
    </div>
    @if ($page->slug === 'about')
        <x-marketing.cta-band />
    @endif
</x-layouts.site>
