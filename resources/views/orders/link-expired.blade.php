<x-layouts.site :seo="\App\Support\Seo::make('Link expired', index: false)" main-class="bg-[#f7f5f1]">
    <div class="container-site max-w-lg py-20 text-center">
        <x-ui.icon-badge icon="lock" size="lg" class="mx-auto" />
        <h1 class="display-2 mt-6">This link has expired or isn’t valid</h1>
        <p class="lead mt-4">For your security, order links expire after a while. We can email you a fresh one.</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ route('orders.lookup') }}" class="btn-primary">Get a new link</a>
            <a href="{{ route('account.login') }}" class="btn-outline">Sign in with email</a>
        </div>
    </div>
</x-layouts.site>
