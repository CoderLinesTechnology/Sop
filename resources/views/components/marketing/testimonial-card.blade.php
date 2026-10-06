@props(['testimonial'])
<figure class="flex h-full flex-col rounded-[10px] border border-line bg-white p-6">
    @if ($testimonial->rating)
        <x-ui.stars :rating="$testimonial->rating" />
    @endif
    <blockquote class="mt-3 flex-1 text-[0.92rem] leading-relaxed text-body">&ldquo;{{ $testimonial->quote }}&rdquo;</blockquote>
    <figcaption class="mt-5 flex items-center gap-3">
        @if ($testimonial->avatar_path && ($avatar = \App\Support\ResponsiveImage::resolve($testimonial->avatar_path)))
            <img src="{{ $avatar['src'] }}" alt="" width="40" height="40" class="size-10 rounded-full object-cover" loading="lazy">
        @else
            <span class="inline-flex size-10 items-center justify-center rounded-full bg-tint-green font-serif text-base text-tint-green-ink" aria-hidden="true">{{ mb_substr($testimonial->author_name, 0, 1) }}</span>
        @endif
        <span class="leading-tight">
            <span class="block text-[0.86rem] font-semibold text-ink">{{ $testimonial->author_name }}</span>
            @if ($testimonial->author_detail)
                <span class="block text-[0.78rem] text-muted">{{ $testimonial->author_detail }}</span>
            @endif
        </span>
    </figcaption>
</figure>
