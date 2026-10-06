@props(['faqs', 'compact' => false])
<div {{ $attributes->class(['divide-y divide-line overflow-hidden rounded-[10px] border border-line bg-white']) }}>
    @foreach ($faqs as $faq)
        <details class="group" @if ($loop->first && ! $compact) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 text-left {{ $compact ? 'text-[0.9rem]' : 'text-[0.97rem]' }} font-medium text-ink hover:bg-cream">
                <span>{{ $faq->question }}</span>
                <x-icon name="plus" class="size-4 shrink-0 text-muted transition group-open:rotate-45" />
            </summary>
            <div class="prose-st px-5 pb-5 text-[0.94rem]">{!! \Illuminate\Support\Str::markdown($faq->answer, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
        </details>
    @endforeach
</div>
