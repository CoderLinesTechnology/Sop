<x-layouts.site :seo="\App\Support\Seo::make('Your answers are waiting', index: false)" main-class="bg-[#f7f5f1]">
    <div class="container-site max-w-lg py-20 text-center">
        <x-ui.icon-badge icon="message" color="yellow" size="lg" class="mx-auto" />
        <h1 class="display-2 mt-6">Your answers are saved on this device, but not sent yet</h1>
        <p class="lead mt-4">For your security, the page stopped accepting answers after a long time open. Open your order again: what you typed will be filled in for you, so you only need to press Send.</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ route('orders.show', $order) }}" class="btn-primary">Open my order</a>
            <a href="{{ route('orders.lookup') }}" class="btn-outline">Email me a new link</a>
        </div>
    </div>
</x-layouts.site>
