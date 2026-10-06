@props(['service'])
<article class="group relative flex h-full flex-col rounded-[10px] border border-line bg-white p-5 transition hover:border-line-strong hover:shadow-card">
    <x-ui.icon-badge :icon="$service->icon" color="white" />
    <h3 class="mt-4 text-[1.12rem] leading-snug">
        <a href="{{ route('services.show', $service->slug) }}" class="after:absolute after:inset-0 focus:outline-none">{{ $service->name }}</a>
    </h3>
    <p class="mt-2.5 text-[0.84rem] leading-relaxed text-muted">{{ $service->short_description }}</p>
    <span class="mt-auto inline-flex items-center gap-1.5 border-t border-line pt-4 text-[0.82rem] font-semibold text-ink transition group-hover:text-brand-700">
        Get Started <x-icon name="arrow-right" class="size-3.5" />
    </span>
</article>
