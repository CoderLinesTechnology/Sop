@props(['title' => 'Ready to tell your story?', 'text' => null, 'button' => 'Start Your Application', 'image' => null])
<section class="relative isolate overflow-hidden bg-brand-800">
    @if ($image && ($bg = \App\Support\ResponsiveImage::resolve($image)))
        <img src="{{ $bg['src'] }}" alt="" width="{{ $bg['width'] }}" height="{{ $bg['height'] }}" loading="lazy" decoding="async"
             class="absolute inset-0 -z-10 h-full w-full object-cover opacity-45">
        <div class="absolute inset-0 -z-10 bg-gradient-to-b from-brand-900/70 via-brand-800/75 to-brand-900/80"></div>
    @else
        <div class="absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_top,rgba(255,255,255,0.08),transparent_60%)]"></div>
    @endif
    <div class="container-site py-14 text-center sm:py-16">
        <h2 class="text-[1.85rem] text-white sm:text-[2.2rem]">{{ $title }}</h2>
        @if ($text)
            <p class="mx-auto mt-3 max-w-xl text-[0.95rem] text-white/85">{{ $text }}</p>
        @endif
        <a href="{{ route('order.start') }}" class="btn-white mt-7">{{ $button }} <x-icon name="arrow-right" class="size-4" /></a>
    </div>
</section>
