@php
    $hero = $page?->section('hero') ?? [];
    $details = $page?->section('details') ?? [];
    $how = $home?->section('how_it_works') ?? [];
@endphp
<x-layouts.site :seo="$seo">
    <section class="border-b border-line bg-[#f7f5f1]">
        <div class="container-site max-w-4xl py-14 text-center sm:py-20">
            <p class="eyebrow">{{ $hero['eyebrow'] ?? 'How it works' }}</p>
            <h1 class="display-1 mt-4">{!! nl2br(e($hero['title'] ?? 'Simple for you. Thorough behind the scenes.')) !!}</h1>
            <p class="lead mx-auto mt-6 max-w-2xl">{{ $hero['text'] ?? '' }}</p>
            <a href="{{ route('order.start') }}" class="btn-primary mt-8">Start Your Application <x-icon name="arrow-right" class="size-4" /></a>
        </div>
    </section>

    <section class="py-16 sm:py-20" aria-labelledby="steps-heading">
        <div class="container-site">
            <x-ui.section-heading id="steps-heading" :center="true" eyebrow="Your part" :title="$how['title'] ?? 'A simple process, a powerful result.'" :text="$how['text'] ?? null" />
            <ol class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($how['steps'] ?? [] as $step)
                    <li class="card p-6">
                        <div class="flex items-center justify-between">
                            <x-ui.icon-badge :icon="$step['icon'] ?? 'document'" />
                            <span class="font-serif text-3xl text-line-strong">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                        </div>
                        <h3 class="mt-5 text-[1.15rem]">{{ $step['title'] }}</h3>
                        <p class="mt-2 text-[0.88rem] leading-relaxed text-muted">{{ $step['text'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    <section class="border-y border-line bg-sand/70 py-16 sm:py-20" aria-labelledby="behind-heading">
        <div class="container-site grid gap-12 lg:grid-cols-[1fr_1.5fr]">
            <div class="lg:sticky lg:top-24 lg:self-start">
                <p class="eyebrow">Behind the scenes</p>
                <h2 id="behind-heading" class="display-2 mt-3">What we do while you get on with your day.</h2>
                <p class="lead mt-4">You can close the page after paying. Everything below happens on our side, and you'll get an email when your document is ready.</p>
            </div>
            <ol class="relative space-y-8 border-l border-line-strong pl-8">
                @foreach ($details as $detail)
                    <li class="relative">
                        <span class="absolute top-1 -left-[2.55rem] inline-flex size-5 items-center justify-center rounded-full border-2 border-brand-700 bg-white"></span>
                        <h3 class="text-[1.2rem]">{{ $detail['title'] }}</h3>
                        <p class="mt-2 text-[0.94rem] leading-relaxed text-body">{{ $detail['text'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    <section class="py-16 sm:py-20" aria-labelledby="hiw-faq">
        <div class="container-site grid gap-10 lg:grid-cols-[1fr_1.3fr] lg:gap-16">
            <div>
                <p class="eyebrow">FAQ</p>
                <h2 id="hiw-faq" class="display-2 mt-3">Good questions</h2>
                <a href="{{ route('pages.faq') }}" class="btn-outline mt-7">View all FAQs <x-icon name="arrow-right" class="size-4" /></a>
            </div>
            <x-ui.faq-list :faqs="$faqs" :compact="true" />
        </div>
    </section>

    <x-marketing.cta-band />
</x-layouts.site>
