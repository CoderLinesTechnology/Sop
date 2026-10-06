@props(['navItems' => [], 'siteName' => 'Statementra', 'bare' => false])
<header class="sticky top-0 z-40 border-b border-line bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/85">
    <div class="container-site flex h-[4.25rem] items-center justify-between gap-6">
        <a href="{{ route('home') }}" class="font-serif text-[1.7rem] leading-none tracking-[-0.02em] text-brand-700 sm:text-[1.85rem]" aria-label="{{ $siteName }} home">
            {{ $siteName }}
        </a>

        @unless ($bare)
            <nav aria-label="Main" class="hidden items-center gap-8 lg:flex">
                @foreach ($navItems as $item)
                    <a href="{{ $item['url'] }}"
                       @class([
                           'relative py-6 text-[0.9rem] font-medium transition-colors',
                           'text-ink after:absolute after:inset-x-0 after:bottom-4 after:h-0.5 after:rounded-full after:bg-brand-700' => $item['active'],
                           'text-body hover:text-brand-700' => ! $item['active'],
                       ])
                       @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endforeach
            </nav>
        @endunless

        <div class="flex items-center gap-2">
            <a href="{{ route('order.start') }}" class="btn-primary hidden min-h-10 px-4 py-2 text-[0.86rem] sm:inline-flex">Get Started</a>
            @unless ($bare)
                <button type="button" class="inline-flex size-11 items-center justify-center rounded-md text-ink hover:bg-sand lg:hidden"
                        data-nav-toggle aria-expanded="false" aria-controls="mobile-nav">
                    <span class="sr-only">Menu</span>
                    <x-icon name="menu" class="size-6" />
                </button>
            @endunless
        </div>
    </div>

    @unless ($bare)
        <div id="mobile-nav" class="border-t border-line bg-white lg:hidden" hidden>
            <nav aria-label="Mobile" class="container-site flex flex-col py-3">
                @foreach ($navItems as $item)
                    <a href="{{ $item['url'] }}" @class(['rounded-md px-2 py-3 text-base font-medium', 'text-brand-700' => $item['active'], 'text-ink' => ! $item['active']])
                       @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endforeach
                <a href="{{ route('account.login') }}" class="rounded-md px-2 py-3 text-base font-medium text-ink">Find my order</a>
                <a href="{{ route('order.start') }}" class="btn-primary mt-3 w-full">Get Started <x-icon name="arrow-right" class="size-4" /></a>
            </nav>
        </div>
    @endunless
</header>
