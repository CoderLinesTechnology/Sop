@props(['icon' => 'document', 'color' => 'green', 'size' => 'md'])
@php
    $tints = [
        'green' => 'bg-tint-green text-tint-green-ink',
        'blue' => 'bg-tint-blue text-tint-blue-ink',
        'purple' => 'bg-tint-purple text-tint-purple-ink',
        'yellow' => 'bg-tint-yellow text-tint-yellow-ink',
        'teal' => 'bg-tint-teal text-tint-teal-ink',
        'rose' => 'bg-tint-rose text-tint-rose-ink',
        'white' => 'bg-white text-brand-700 ring-1 ring-line',
    ];
    $sizes = ['sm' => ['size-9', 'size-[1.05rem]'], 'md' => ['size-12', 'size-[1.35rem]'], 'lg' => ['size-14', 'size-6']];
    [$box, $glyph] = $sizes[$size] ?? $sizes['md'];
@endphp
<span {{ $attributes->class(['inline-flex shrink-0 items-center justify-center rounded-full', $box, $tints[$color] ?? $tints['green']]) }}>
    <x-icon :name="$icon" :class="$glyph" />
</span>
