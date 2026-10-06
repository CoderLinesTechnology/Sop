<x-layouts.site :seo="$seo" main-class="bg-[#f7f5f1]">
    <div class="container-site max-w-xl py-20 text-center">
        <x-ui.icon-badge icon="{{ ($review ?? false) ? 'info' : 'check' }}" size="lg" class="mx-auto" />
        <h1 class="display-2 mt-6">{{ ($review ?? false) ? 'We’re checking your payment' : 'Payment received' }}</h1>
        <p class="lead mt-4">
            @if ($review ?? false)
                Thank you. Our team is reviewing your payment and will be in touch by email shortly. You don’t need to pay again.
            @else
                Thank you — your payment is confirmed. We’ve emailed you a secure link to follow your order and receive your document.
            @endif
        </p>
        <a href="{{ route('home') }}" class="btn-outline mt-8">Back to home</a>
    </div>
</x-layouts.site>
