<x-layouts.site :seo="$seo" main-class="bg-[#f7f5f1]">
    <div class="container-site max-w-4xl py-12">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow">Your account</p>
                <h1 class="display-2 mt-3">Your orders</h1>
                <p class="mt-2 text-sm text-muted">Signed in as {{ $user->email }}</p>
            </div>
            <form method="POST" action="{{ route('account.logout') }}">@csrf<button type="submit" class="btn-ghost">Sign out</button></form>
        </div>

        <div class="card mt-8 divide-y divide-line">
            @forelse ($orders as $order)
                <a href="{{ route('orders.show', $order->public_id) }}" class="flex flex-col gap-2 px-5 py-4 transition hover:bg-cream sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="font-semibold text-ink">{{ $order->serviceName() }}</p>
                        <p class="text-[0.86rem] text-muted">{{ $order->applicationTitle() }} · {{ $order->reference }} · {{ $order->created_at->format('j M Y') }}</p>
                    </div>
                    <span class="inline-flex w-fit items-center rounded-full bg-sand px-3 py-1 text-[0.78rem] font-medium text-ink">{{ $order->status->customerLabel() }}</span>
                </a>
            @empty
                <div class="px-5 py-10 text-center">
                    <p class="text-muted">You don’t have any orders yet.</p>
                    <a href="{{ route('order.start') }}" class="btn-primary mt-5">Start an application</a>
                </div>
            @endforelse
        </div>
        <div class="mt-6">{{ $orders->links() }}</div>
    </div>
</x-layouts.site>
