@props(['article', 'compact' => false])
<article class="group relative flex h-full flex-col overflow-hidden rounded-[10px] border border-line bg-white transition hover:shadow-card">
    <div class="aspect-[2/1] overflow-hidden bg-sand">
        @if ($article->cover_image_path)
            <x-picture :path="$article->cover_image_path" :alt="$article->cover_image_alt ?? ''" sizes="(min-width: 1024px) 280px, (min-width: 640px) 45vw, 100vw"
                       img-class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]" class="block h-full" />
        @endif
    </div>
    <div class="flex flex-1 flex-col p-5">
        @if ($article->category && ! $compact)
            <p class="text-[0.66rem] font-semibold tracking-[0.16em] text-muted uppercase">{{ $article->category->name }}</p>
        @endif
        <h3 class="{{ $compact ? 'text-[0.98rem]' : 'mt-2 text-[1.18rem]' }} leading-snug">
            <a href="{{ route('resources.show', $article->slug) }}" class="after:absolute after:inset-0 focus:outline-none">{{ $article->title }}</a>
        </h3>
        @if (! $compact && $article->excerpt)
            <p class="mt-2.5 text-[0.86rem] leading-relaxed text-muted">{{ $article->excerpt }}</p>
        @endif
        <span class="mt-auto inline-flex items-center gap-1.5 pt-4 text-[0.8rem] font-semibold text-brand-700">Read more <x-icon name="arrow-right" class="size-3.5" /></span>
    </div>
</article>
