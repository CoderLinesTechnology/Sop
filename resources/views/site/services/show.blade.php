<x-layouts.site :seo="$seo">
    <section class="border-b border-line bg-[#f7f5f1]">
        <div class="container-site grid gap-12 py-12 lg:grid-cols-[1.4fr_1fr] lg:py-16">
            <div>
                <nav aria-label="Breadcrumb" class="text-[0.8rem] text-muted">
                    <a href="{{ route('services.index') }}" class="hover:text-brand-700">Services</a>
                    <span aria-hidden="true" class="mx-1.5">/</span>
                    <span aria-current="page">{{ $service->name }}</span>
                </nav>
                <div class="mt-6 flex items-center gap-4">
                    <x-ui.icon-badge :icon="$service->icon" :color="$service->icon_color" size="lg" />
                    @if ($service->badge)
                        <span class="rounded-full bg-brand-700 px-3 py-1 text-[0.7rem] font-semibold text-white">{{ $service->badge }}</span>
                    @endif
                </div>
                <h1 class="display-1 mt-5">{{ $service->name }}</h1>
                <p class="lead mt-5 max-w-2xl">{{ $service->short_description }}</p>

                @if ($service->card_features)
                    <ul class="mt-7 grid gap-3 sm:grid-cols-2">
                        @foreach ($service->card_features as $feature)
                            <li class="flex items-center gap-2.5 text-[0.93rem] text-ink"><x-icon name="check" class="size-4 text-brand-600" stroke="2.25" /> {{ $feature }}</li>
                        @endforeach
                        <li class="flex items-center gap-2.5 text-[0.93rem] text-ink"><x-icon name="check" class="size-4 text-brand-600" stroke="2.25" /> PDF + editable Word document</li>
                        <li class="flex items-center gap-2.5 text-[0.93rem] text-ink"><x-icon name="check" class="size-4 text-brand-600" stroke="2.25" /> {{ $service->revisions_included }} revision{{ $service->revisions_included === 1 ? '' : 's' }} included</li>
                    </ul>
                @endif
            </div>

            <aside class="lg:pt-10">
                <div class="card p-6 shadow-card sm:p-7">
                    <p class="text-sm font-medium text-muted">Price</p>
                    <x-ui.price :quote="$quote" class="mt-2" />
                    @if ($endsAt = $quote->countdownEndsAt())
                        <p class="mt-3 flex items-center gap-2 text-[0.84rem] text-brand-700" data-countdown data-ends-at="{{ $endsAt->toIso8601String() }}" data-server-now="{{ now()->toIso8601String() }}">
                            <x-icon name="clock" class="size-4" /> {{ $quote->promotion?->label ?: 'Limited-time offer' }} ends in <span class="font-semibold tabular-nums" data-unit="compact">—</span>
                        </p>
                    @endif
                    <dl class="mt-6 space-y-3 border-t border-line pt-5 text-[0.88rem]">
                        <div class="flex justify-between gap-4"><dt class="text-muted">Delivery</dt><dd class="font-medium text-ink">{{ $service->deliveryLabel() }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-muted">Format</dt><dd class="font-medium text-ink">PDF + Word (.docx)</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-muted">Account</dt><dd class="font-medium text-ink">Not required</dd></div>
                    </dl>
                    <a href="{{ route('order.start', $service->slug) }}" class="btn-primary mt-6 w-full">Get Started <x-icon name="arrow-right" class="size-4" /></a>
                    <p class="mt-4 flex items-center justify-center gap-2 text-xs text-muted"><x-icon name="lock" class="size-3.5" /> Secure payment via Paystack</p>
                </div>
            </aside>
        </div>
    </section>

    <section class="py-14 sm:py-16">
        <div class="container-site grid gap-12 lg:grid-cols-[1.4fr_1fr]">
            <div>
                @if ($service->description)
                    <div class="prose-st max-w-2xl">{!! \Illuminate\Support\Str::markdown($service->description, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                @endif

                <h2 class="mt-12 text-[1.6rem]">What happens after you order</h2>
                <ol class="mt-6 space-y-5">
                    @foreach ([
                        ['Tell us about your application', 'Institution, programme, the question you need to answer and a little about you. Upload your CV to save time.'],
                        ['We research and verify', 'We study your programme and institution on official sources and check the requirements — word limits, structure, language conventions.'],
                        ['We write, refine and fact-check', 'A personalized draft built from your real experience, edited for a natural voice and checked against every requirement.'],
                        ['You receive your documents', 'A submission-ready PDF and an editable Word file, emailed to you — usually within '.$service->deliveryLabel().'.'],
                    ] as [$title, $text])
                        <li class="flex gap-4">
                            <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-tint-green text-sm font-semibold text-tint-green-ink">{{ $loop->iteration }}</span>
                            <span><span class="block font-semibold text-ink">{{ $title }}</span><span class="mt-1 block text-[0.92rem] leading-relaxed text-muted">{{ $text }}</span></span>
                        </li>
                    @endforeach
                </ol>
            </div>
            <div>
                <h2 class="text-[1.4rem]">Questions</h2>
                <x-ui.faq-list :faqs="$faqs" :compact="true" class="mt-5" />
            </div>
        </div>
    </section>

    <x-marketing.cta-band :text="'Start your '.strtolower($service->name).' now — no account required.'" />
</x-layouts.site>
