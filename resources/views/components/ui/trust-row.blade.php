@props(['delivery' => '20–30 minutes', 'variant' => 'default'])
<ul {{ $attributes->class(['flex flex-wrap gap-x-7 gap-y-4 sm:gap-x-0 sm:divide-x sm:divide-line']) }}>
    @foreach ([
        ['icon' => 'lock', 'title' => 'Secure Payments', 'text' => '(powered by Paystack)'],
        ['icon' => 'clock', 'title' => 'Fast Delivery', 'text' => '('.$delivery.')'],
        ['icon' => 'shield-check', 'title' => '100% Confidential', 'text' => 'Your privacy matters'],
    ] as $item)
        <li class="flex items-center gap-3 sm:px-6 sm:first:pl-0">
            <x-icon :name="$item['icon']" class="size-6 shrink-0 text-brand-700" stroke="1.5" />
            <span class="leading-tight">
                <span class="block text-[0.8rem] font-semibold text-ink">{{ $item['title'] }}</span>
                <span class="block text-[0.74rem] text-muted">{{ $item['text'] }}</span>
            </span>
        </li>
    @endforeach
</ul>
