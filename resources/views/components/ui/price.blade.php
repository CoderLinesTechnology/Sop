@props(['quote', 'size' => 'lg', 'pill' => 'save'])
@php
    $original = $quote->displayOriginal();
    $savings = $quote->savingsPercent();
@endphp
<div {{ $attributes->class(['flex flex-wrap items-center gap-x-3 gap-y-1']) }}>
    @if ($original)
        <span class="text-[1.05rem] text-muted line-through decoration-1" aria-label="Original price {{ $quote->format($original) }}">{{ $quote->format($original) }}</span>
    @endif
    <span @class([
        'font-sans font-bold tracking-tight text-ink',
        'text-[1.9rem] leading-none' => $size === 'lg',
        'text-2xl leading-none' => $size === 'md',
        'text-lg' => $size === 'sm',
    ])>{{ $quote->format($quote->total) }}</span>
    @if ($savings > 0)
        @if ($pill === 'off')
            <span class="pill-off">{{ $savings }}% off</span>
        @else
            <span class="pill-save">Save {{ $savings }}%</span>
        @endif
    @endif
</div>
