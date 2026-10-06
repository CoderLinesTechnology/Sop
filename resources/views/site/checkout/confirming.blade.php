<x-layouts.site :seo="$seo" main-class="bg-[#f7f5f1]" :bare="true">
    <x-slot:head><meta http-equiv="refresh" content="5;url={{ $retryUrl }}"></x-slot:head>
    <div class="container-site max-w-xl py-20 text-center">
        <x-icon name="loader" class="mx-auto size-10 animate-spin text-brand-700" />
        <h1 class="display-2 mt-6">Confirming your payment…</h1>
        <p class="lead mt-4">We’re verifying your payment with Paystack. This usually takes a few seconds — this page will refresh automatically.</p>
        <p class="mt-6 text-sm text-muted">If you’ve been charged, your order is safe: we’ll email you as soon as the payment is confirmed.</p>
        <a href="{{ $retryUrl }}" class="btn-outline mt-8">Check again</a>
    </div>
</x-layouts.site>
