@props([
    'path',
    'alt' => '',
    'sizes' => '100vw',
    'eager' => false,
    'imgClass' => '',
])
@php($image = \App\Support\ResponsiveImage::resolve($path))
@if ($image)
    <picture {{ $attributes }}>
        @if ($image['srcset'])
            <source type="image/webp" srcset="{{ $image['srcset'] }}" sizes="{{ $sizes }}">
        @endif
        <img src="{{ $image['fallback'] }}" alt="{{ $alt }}" width="{{ $image['width'] }}" height="{{ $image['height'] }}"
             class="{{ $imgClass }}" decoding="async"
             @if ($eager) loading="eager" fetchpriority="high" @else loading="lazy" @endif>
    </picture>
@endif
