<x-layouts.site :seo="$seo" main-class="bg-[#f7f5f1]">
    <div class="container-site max-w-lg py-16">
        <p class="eyebrow">Partner programme</p>
        @if ($state === 'confirmed')
            <h1 class="display-2 mt-3">Thanks — you’re confirmed</h1>
            <div class="mt-6 flex items-start gap-3 rounded-md bg-mint px-4 py-3 text-sm text-mint-ink" role="status">
                <x-icon name="check-circle" class="mt-0.5 size-5 shrink-0" /> Your application is now with our team. We’ll email you once we’ve reviewed it.
            </div>
        @elseif ($state === 'pending')
            <h1 class="display-2 mt-3">Confirm your application</h1>
            <p class="lead mt-3">Press the button to confirm your email address. We review your application once it’s confirmed.</p>
            <form method="POST" action="{{ route('partners.apply.confirm.store', $token) }}" class="mt-6">
                @csrf
                <button type="submit" class="btn-primary w-full">Confirm my application</button>
            </form>
        @else
            <h1 class="display-2 mt-3">This link has expired</h1>
            <p class="lead mt-3">The confirmation link is no longer valid: it was already used or is more than 48 hours old.</p>
            <a href="{{ route('partners.apply') }}" class="btn-outline mt-6">Apply again</a>
        @endif
    </div>
</x-layouts.site>
