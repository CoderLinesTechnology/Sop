@props(['service', 'quote', 'featured' => false])
<article @class([
    'relative flex h-full flex-col rounded-[10px] border bg-white p-6 transition sm:p-7',
    'border-brand-300 bg-gradient-to-b from-brand-50/70 to-white shadow-card ring-1 ring-brand-200' => $featured,
    'border-line hover:border-line-strong hover:shadow-card' => ! $featured,
])>
    <div class="flex items-start justify-between gap-4">
        <x-ui.icon-badge :icon="$service->icon" :color="$service->icon_color" size="lg" />
        @if ($service->badge)
            <span class="rounded-full bg-brand-700 px-3 py-1 text-[0.7rem] font-semibold text-white">{{ $service->badge }}</span>
        @endif
    </div>

    <h3 class="mt-5 text-[1.45rem] leading-tight">
        <a href="{{ route('services.show', $service->slug) }}" class="after:absolute after:inset-0 focus:outline-none">{{ $service->name }}</a>
    </h3>
    <p class="mt-3 text-[0.92rem] leading-relaxed text-muted">{{ $service->short_description }}</p>

    @if ($service->card_features)
        <ul class="mt-5 space-y-2 text-[0.88rem] text-body">
            @foreach ($service->card_features as $feature)
                <li class="flex items-start gap-2.5"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-brand-600" stroke="2.25" />{{ $feature }}</li>
            @endforeach
        </ul>
    @endif

    <div class="mt-auto pt-7">
        <x-ui.price :quote="$quote" />
        <p class="mt-4 flex items-center gap-2 text-[0.84rem] text-muted">
            <x-icon name="clock" class="size-4 text-ink" /> Delivery: {{ $service->deliveryLabel() }}
        </p>
        <a href="{{ route('order.start', $service->slug) }}" @class(['relative z-10 mt-5 w-full', 'btn-primary' => $featured, 'btn-outline' => ! $featured])>
            Get Started <x-icon name="arrow-right" class="size-4" />
        </a>
    </div>
</article>
