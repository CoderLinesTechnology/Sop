@props(['service', 'quote', 'tagline' => null])
@php($endsAt = $quote->countdownEndsAt())
<div class="card grid gap-6 p-5 shadow-card sm:p-6 lg:grid-cols-[1.4fr_1fr_auto_1.25fr] lg:items-center lg:gap-0 lg:divide-x lg:divide-line">
    <div class="lg:pr-6">
        <h3 class="font-sans text-[1.02rem] font-semibold text-ink">{{ $service->name }}</h3>
        <p class="mt-1 text-[0.85rem] text-muted">{{ $tagline ?: $service->short_description }}</p>
    </div>

    <div class="lg:px-6">
        <x-ui.price :quote="$quote" pill="off" />
    </div>

    @if ($endsAt)
        <div class="lg:px-6" data-countdown data-ends-at="{{ $endsAt->toIso8601String() }}" data-server-now="{{ now()->toIso8601String() }}">
            <p class="text-[0.75rem] text-muted">Offer ends in:</p>
            <div class="mt-1.5 flex items-center gap-1.5 text-center" role="timer" aria-live="off">
                @foreach (['days' => 'Days', 'hours' => 'Hours', 'minutes' => 'Minutes', 'seconds' => 'Seconds'] as $unit => $label)
                    <span class="flex flex-col items-center">
                        <span class="inline-flex min-w-11 justify-center rounded-md bg-sand px-2 py-2 font-sans text-base font-semibold tabular-nums text-ink" data-unit="{{ $unit }}">--</span>
                        <span class="mt-1 text-[0.65rem] text-muted">{{ $label }}</span>
                    </span>
                    @unless ($loop->last)<span class="-mt-4 font-semibold text-muted" aria-hidden="true">:</span>@endunless
                @endforeach
            </div>
        </div>
    @else
        <div class="hidden lg:block"></div>
    @endif

    <form method="GET" action="{{ route('order.start', $service->slug) }}" class="space-y-2.5 lg:pl-6">
        <div class="flex rounded-md border border-line-strong bg-white p-1 focus-within:border-brand-600 focus-within:ring-2 focus-within:ring-brand-100">
            <label for="offer-coupon" class="sr-only">Coupon code</label>
            <input id="offer-coupon" name="coupon" type="text" maxlength="40" autocomplete="off" placeholder="Enter coupon code"
                   class="min-w-0 flex-1 border-0 bg-transparent px-2.5 text-[0.85rem] uppercase placeholder:normal-case placeholder:text-muted/70 focus:outline-none">
            <button type="submit" class="rounded bg-brand-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-800">Apply</button>
        </div>
        <a href="{{ route('order.start', $service->slug) }}" class="btn-primary w-full">Get Started <x-icon name="arrow-right" class="size-4" /></a>
    </form>
</div>
