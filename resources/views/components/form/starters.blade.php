@props(['starters' => [], 'target'])
{{-- Clickable sentence starters for a textarea (resources/js/app.js inserts them; no AI involved). --}}
@if ($starters !== [])
    <div class="mt-2 flex flex-wrap items-center gap-1.5">
        <span class="text-[0.78rem] text-muted">Not sure how to start? Tap one:</span>
        @foreach ($starters as $starter)
            <button type="button" data-starter="{{ $starter }}" data-starter-target="{{ $target }}"
                    class="rounded-full border border-line-strong bg-white px-3 py-1 text-left text-[0.78rem] text-ink transition hover:border-brand-600 hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">{{ $starter }}</button>
        @endforeach
    </div>
@endif
