@php
    $hero = $page?->section('hero') ?? [];
    $servicesSection = $page?->section('services') ?? [];
    $how = $page?->section('how_it_works') ?? [];
    $research = $page?->section('research') ?? [];
    $offerSection = $page?->section('offer') ?? [];
    $testimonialsSection = $page?->section('testimonials') ?? [];
    $resourcesSection = $page?->section('resources') ?? [];
    $faqSection = $page?->section('faq') ?? [];
    $cta = $page?->section('cta') ?? [];
    $deliveryLabel = ($services->first()['service'] ?? null)?->deliveryLabel() ?? '20–30 minutes';
@endphp
<x-layouts.site :seo="$seo">
    {{-- Hero --}}
    <section class="relative overflow-hidden border-b border-line bg-[#f7f5f1]">
        <div class="container-site grid items-center gap-10 py-12 lg:grid-cols-[1fr_1.05fr] lg:gap-0 lg:py-0">
            <div class="relative z-10 py-4 lg:py-20 lg:pr-12">
                <h1 class="display-1 max-w-xl">{{ $hero['title'] ?? 'Your story deserves more than a generic essay.' }}</h1>
                <p class="lead mt-6 max-w-lg">{{ $hero['text'] ?? '' }}</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('order.start') }}" class="btn-primary">{{ $hero['primary_cta'] ?? 'Start Your Application' }} <x-icon name="arrow-right" class="size-4" /></a>
                    <a href="{{ route('pages.how-it-works') }}" class="btn-outline">{{ $hero['secondary_cta'] ?? 'See How It Works' }}</a>
                </div>
                <x-ui.trust-row class="mt-10" :delivery="$deliveryLabel" />
                @if (! empty($hero['trust_note']))
                    <p class="mt-6 text-xs text-muted">{{ $hero['trust_note'] }}</p>
                @endif
            </div>

            <div class="relative -mx-4 sm:mx-0 lg:-mr-[max(2rem,calc((100vw-1200px)/2+2rem))] lg:h-full">
                <x-picture :path="$hero['image'] ?? 'images/home/hero.jpg'" :alt="$hero['image_alt'] ?? ''" :eager="true"
                           sizes="(min-width: 1024px) 55vw, 100vw"
                           img-class="h-full max-h-[540px] w-full object-cover lg:max-h-none lg:min-h-[520px]" class="block h-full" />
                <div class="pointer-events-none absolute inset-y-0 left-0 hidden w-40 bg-gradient-to-r from-[#f7f5f1] to-transparent lg:block"></div>
                @if (! empty($hero['image_caption']))
                    <p class="absolute top-8 right-8 hidden max-w-[16rem] border-b border-white/70 pb-4 font-serif text-[1.15rem] leading-snug text-white italic drop-shadow-[0_1px_8px_rgba(0,0,0,0.35)] sm:block">
                        {!! nl2br(e($hero['image_caption'])) !!}
                    </p>
                @endif
            </div>
        </div>
    </section>

    {{-- Services --}}
    <section class="py-16 sm:py-20" aria-labelledby="services-heading">
        <div class="container-site">
            <x-ui.section-heading id="services-heading" :eyebrow="$servicesSection['eyebrow'] ?? 'Our services'" :title="$servicesSection['title'] ?? 'Application documents for every goal.'" :aside="$servicesSection['text'] ?? null" />
            <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-{{ min(5, max(3, $services->count())) }}">
                @foreach ($services as $item)
                    <x-marketing.service-card-compact :service="$item['service']" />
                @endforeach
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section id="how-it-works" class="border-y border-line bg-sand/70 py-16 sm:py-20" aria-labelledby="how-heading">
        <div class="container-site">
            <x-ui.section-heading id="how-heading" :eyebrow="$how['eyebrow'] ?? 'How it works'" :title="$how['title'] ?? 'A simple process, a powerful result.'" :aside="$how['text'] ?? null" />
            <ol class="mt-12 grid gap-10 sm:grid-cols-2 lg:grid-cols-4 lg:gap-6">
                @foreach ($how['steps'] ?? [] as $step)
                    <li class="relative">
                        <div class="flex items-center gap-4">
                            <span class="inline-flex size-10 items-center justify-center rounded-full border border-line-strong bg-white text-sm font-semibold text-ink tabular-nums">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <x-icon :name="$step['icon'] ?? 'document'" class="size-7 text-ink" stroke="1.4" />
                            @unless ($loop->last)
                                <x-icon name="arrow-right" class="ml-auto hidden size-5 text-line-strong lg:block" />
                            @endunless
                        </div>
                        <h3 class="mt-5 text-[1.18rem]">{{ $step['title'] }}</h3>
                        <p class="mt-2 max-w-[16rem] text-[0.88rem] leading-relaxed text-muted">{{ $step['text'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Research process --}}
    <section class="py-16 sm:py-20" aria-labelledby="research-heading">
        <div class="container-site grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
            <div class="relative">
                <x-picture :path="$research['image'] ?? 'images/home/research.jpg'" :alt="$research['image_alt'] ?? ''" sizes="(min-width: 1024px) 560px, 100vw"
                           img-class="aspect-[16/9] w-full rounded-[10px] object-cover" class="block" />
                @if (! empty($research['image_caption']))
                    <p class="absolute right-4 -bottom-6 max-w-[15rem] rounded-md bg-brand-800 px-6 py-5 font-serif text-[1.05rem] leading-snug text-white italic shadow-lift sm:right-8">
                        {!! nl2br(e($research['image_caption'])) !!}
                    </p>
                @endif
            </div>
            <div>
                <p class="eyebrow">{{ $research['eyebrow'] ?? 'Our research process' }}</p>
                <h2 id="research-heading" class="display-2 mt-3">{{ $research['title'] ?? 'We go beyond the basics.' }}</h2>
                <p class="lead mt-4 max-w-lg">{{ $research['text'] ?? '' }}</p>
                <ul class="mt-8 grid gap-x-10 gap-y-4 sm:grid-cols-2">
                    @foreach ($research['points'] ?? [] as $point)
                        <li class="flex items-center gap-3 text-[0.92rem] text-ink">
                            <x-icon name="check-circle" class="size-5 shrink-0 text-brand-600" /> {{ $point }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    {{-- Special offer --}}
    @if ($offer)
        <section class="border-t border-line bg-[#f7f5f1] py-16 sm:py-20" aria-labelledby="offer-heading">
            <div class="container-site">
                <x-ui.section-heading id="offer-heading" :center="true" :eyebrow="$offerSection['eyebrow'] ?? 'Special offer'" :title="$offerSection['title'] ?? 'Get the support you need, at the right price.'" :text="$offerSection['text'] ?? null" />
                <div class="mx-auto mt-10 max-w-5xl">
                    <x-marketing.offer-card :service="$offer['service']" :quote="$offer['quote']" :tagline="$offerSection['tagline'] ?? null" />
                </div>
            </div>
        </section>
    @endif

    {{-- Testimonials --}}
    @if ($testimonials->isNotEmpty())
        <section class="py-16 sm:py-20" aria-labelledby="testimonials-heading">
            <div class="container-site">
                <x-ui.section-heading id="testimonials-heading" :center="true" :eyebrow="$testimonialsSection['eyebrow'] ?? 'What our clients say'" :title="$testimonialsSection['title'] ?? 'Ready to support you worldwide.'" :text="$testimonialsSection['text'] ?? null" />
                <div class="mt-10 grid gap-5 md:grid-cols-3">
                    @foreach ($testimonials as $testimonial)
                        <x-marketing.testimonial-card :testimonial="$testimonial" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Resources + FAQ --}}
    <section class="border-t border-line py-16 sm:py-20">
        <div class="container-site grid gap-14 lg:grid-cols-[1.45fr_1fr] lg:gap-12">
            <div aria-labelledby="resources-heading">
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <p class="eyebrow">{{ $resourcesSection['eyebrow'] ?? 'Resources' }}</p>
                        <h2 id="resources-heading" class="mt-3 text-[1.6rem]">{{ $resourcesSection['title'] ?? 'Guides & Writing Resources' }}</h2>
                        <p class="mt-2 text-[0.9rem] text-muted">{{ $resourcesSection['text'] ?? '' }}</p>
                    </div>
                    <a href="{{ route('resources.index') }}" class="link hidden shrink-0 text-sm sm:inline">All guides</a>
                </div>
                @if ($articles->isNotEmpty())
                    <div class="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach ($articles as $article)
                            <x-marketing.article-card :article="$article" :compact="true" />
                        @endforeach
                    </div>
                @else
                    <p class="mt-7 text-sm text-muted">New guides are coming soon.</p>
                @endif
            </div>

            <div aria-labelledby="faq-heading">
                <p class="eyebrow">{{ $faqSection['eyebrow'] ?? 'FAQ' }}</p>
                <h2 id="faq-heading" class="mt-3 text-[1.6rem]">{{ $faqSection['title'] ?? 'Common Questions' }}</h2>
                <p class="mt-2 text-[0.9rem] text-muted">{{ $faqSection['text'] ?? '' }}</p>
                <x-ui.faq-list :faqs="$faqs" :compact="true" class="mt-7" />
                <a href="{{ route('pages.faq') }}" class="link mt-4 inline-block text-sm">See all questions</a>
            </div>
        </div>
    </section>

    <x-marketing.cta-band :title="$cta['title'] ?? 'Ready to tell your story?'" :text="$cta['text'] ?? null" :button="$cta['button'] ?? 'Start Your Application'" :image="$cta['image'] ?? null" />
</x-layouts.site>
