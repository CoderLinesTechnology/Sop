@php
    $hero = $page?->section('hero') ?? [];
    $list = $page?->section('list') ?? [];
    $why = $page?->section('why') ?? [];
    $count = $services->count();
    $remainder = $count % 3;
    $deliveryLabel = ($services->first()['service'] ?? null)?->deliveryLabel() ?? '10–15 minutes';
@endphp
<x-layouts.site :seo="$seo">
    {{-- Hero --}}
    <section class="relative overflow-hidden border-b border-line bg-[#f7f5f1]">
        <div class="container-site grid items-center gap-10 py-12 lg:grid-cols-[1fr_1fr] lg:gap-0 lg:py-0">
            <div class="relative z-10 lg:py-20 lg:pr-12">
                <p class="eyebrow">{{ $hero['eyebrow'] ?? 'Our services' }}</p>
                <h1 class="display-1 mt-4 max-w-xl">{{ $hero['title'] ?? 'Choose the service that fits your goal.' }}</h1>
                <p class="lead mt-6 max-w-lg">{{ $hero['text'] ?? '' }}</p>
                <x-ui.trust-row class="mt-10" :delivery="$deliveryLabel" />
            </div>
            <div class="relative -mx-4 sm:mx-0 lg:-mr-[max(2rem,calc((100vw-1200px)/2+2rem))] lg:h-full">
                <x-picture :path="$hero['image'] ?? 'images/services/hero.jpg'" :alt="$hero['image_alt'] ?? ''" :eager="true" sizes="(min-width: 1024px) 50vw, 100vw"
                           img-class="h-full max-h-[460px] w-full object-cover lg:max-h-none lg:min-h-[440px]" class="block h-full" />
                <div class="pointer-events-none absolute inset-y-0 left-0 hidden w-40 bg-gradient-to-r from-[#f7f5f1] to-transparent lg:block"></div>
                @if (! empty($hero['image_caption']))
                    <p class="absolute top-8 right-8 hidden max-w-[15rem] border-b border-white/70 pb-4 font-serif text-[1.1rem] leading-snug text-white italic drop-shadow-[0_1px_8px_rgba(0,0,0,0.35)] sm:block">
                        {!! nl2br(e($hero['image_caption'])) !!}
                    </p>
                @endif
            </div>
        </div>
    </section>

    {{-- Services --}}
    <section class="py-16 sm:py-20" aria-labelledby="list-heading">
        <div class="container-site">
            <x-ui.section-heading id="list-heading" :eyebrow="$list['eyebrow'] ?? 'Available services'" :title="$list['title'] ?? 'What do you need help with?'" :text="$list['text'] ?? null" />

            <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-6">
                @foreach ($services as $index => $item)
                    @php
                        $span = 'lg:col-span-2';
                        if ($remainder === 2 && $index >= $count - 2) {
                            $span = 'lg:col-span-3';
                        } elseif ($remainder === 1 && $index === $count - 1) {
                            $span = 'lg:col-span-6';
                        }
                    @endphp
                    <div class="{{ $span }}">
                        <x-marketing.service-card :service="$item['service']" :quote="$item['quote']" :featured="$item['service']->is_featured" />
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Why Statementra --}}
    <section class="pb-16 sm:pb-20" aria-labelledby="why-heading">
        <div class="container-site">
            <div class="card grid overflow-hidden bg-[#f4f2ec] lg:grid-cols-[1.15fr_1fr_1fr]">
                <div class="p-7 sm:p-10">
                    <p class="eyebrow">{{ $why['eyebrow'] ?? 'Why Statementra' }}</p>
                    <h2 id="why-heading" class="mt-3 text-[1.65rem] leading-snug sm:text-[1.85rem]">{!! nl2br(e($why['title'] ?? "More than just writing.\nIt's a complete application support service.")) !!}</h2>
                    <p class="mt-4 text-[0.92rem] leading-relaxed text-body">{{ $why['text'] ?? '' }}</p>
                    <a href="{{ route('pages.how-it-works') }}" class="btn-primary mt-7">{{ $why['button'] ?? 'Learn More' }} <x-icon name="arrow-right" class="size-4" /></a>
                </div>

                <ul class="space-y-6 border-line px-7 pb-8 sm:px-10 lg:border-x lg:py-10">
                    @foreach ($why['features'] ?? [] as $feature)
                        <li class="flex gap-4">
                            <x-ui.icon-badge :icon="$feature['icon'] ?? 'check'" color="white" size="sm" />
                            <span>
                                <span class="block text-[0.95rem] font-semibold text-ink">{{ $feature['title'] }}</span>
                                <span class="mt-0.5 block text-[0.84rem] leading-relaxed text-muted">{{ $feature['text'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>

                <figure class="flex flex-col overflow-hidden">
                    @if ($testimonial)
                        <blockquote class="px-7 pt-7 font-serif text-[1.08rem] leading-snug text-ink italic sm:px-10 sm:pt-10">
                            &ldquo;{{ $testimonial->quote }}&rdquo;
                            <footer class="mt-3 font-sans text-[0.8rem] text-muted not-italic">— {{ $testimonial->author_name }}@if ($testimonial->author_detail), {{ $testimonial->author_detail }}@endif</footer>
                        </blockquote>
                    @endif
                    <x-picture path="images/people/testimonial.jpg" alt="" sizes="(min-width: 1024px) 220px, 50vw"
                               img-class="ml-auto h-60 w-auto object-cover object-top [mask-image:linear-gradient(to_left,black_75%,transparent)]"
                               class="mt-auto block pt-4" />
                </figure>
            </div>
        </div>
    </section>
</x-layouts.site>
