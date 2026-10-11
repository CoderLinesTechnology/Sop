@props(['starters' => [], 'target', 'label' => 'Not sure how to start? Tap one:', 'variant' => 'starters'])
{{-- Clickable sentence starters for a textarea (resources/js/app.js inserts them; no AI involved). --}}
@if ($starters !== [] && $variant === 'answers')
    <div class="mt-2 space-y-2">
        <p class="text-[0.78rem] text-muted">{{ $label }}</p>
        @foreach ($starters as $starter)
            <button type="button" data-starter="{{ $starter }}" data-starter-target="{{ $target }}" data-starter-mode="replace"
                    class="block w-full rounded-[10px] border border-line-strong bg-white px-4 py-3 text-left text-[0.88rem] leading-relaxed text-ink transition hover:border-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                <span class="block">{{ $starter }}</span>
                <span class="mt-1.5 block text-[0.75rem] font-semibold text-brand-700">Use this answer</span>
            </button>
        @endforeach
    </div>
@elseif ($starters !== [])
    <div class="mt-2 flex flex-wrap items-center gap-1.5">
        <span class="text-[0.78rem] text-muted">{{ $label }}</span>
        @foreach ($starters as $starter)
            <button type="button" data-starter="{{ $starter }}" data-starter-target="{{ $target }}"
                    class="rounded-full border border-line-strong bg-white px-3 py-1 text-left text-[0.78rem] text-ink transition hover:border-brand-600 hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">{{ $starter }}</button>
        @endforeach
    </div>
@endif
