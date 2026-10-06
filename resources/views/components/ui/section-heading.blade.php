@props(['eyebrow' => null, 'title', 'text' => null, 'aside' => null, 'level' => 'h2', 'center' => false])
<div {{ $attributes->class(['grid gap-4', 'lg:grid-cols-[1.25fr_1fr] lg:items-end lg:gap-16' => $aside && ! $center, 'mx-auto max-w-2xl text-center' => $center]) }}>
    <div>
        @if ($eyebrow)
            <p class="eyebrow">{{ $eyebrow }}</p>
        @endif
        <{{ $level }} class="display-2 mt-3">{!! nl2br(e($title)) !!}</{{ $level }}>
        @if ($text)
            <p class="lead mt-4 {{ $center ? 'mx-auto max-w-xl' : 'max-w-xl' }}">{{ $text }}</p>
        @endif
    </div>
    @if ($aside)
        <p class="max-w-md text-[0.95rem] leading-relaxed text-body lg:justify-self-end">{{ $aside }}</p>
    @endif
</div>
