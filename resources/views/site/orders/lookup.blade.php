<x-layouts.site :seo="$seo" main-class="bg-[#f7f5f1]">
    <div class="container-site max-w-lg py-16">
        <h1 class="display-2">Find my order</h1>
        <p class="lead mt-3">Enter the email address you used and your order reference (it starts with “ST-” and is in your confirmation email). We’ll email you a secure link.</p>
        @if (session('status'))
            <div class="mt-6 rounded-md bg-mint px-4 py-3 text-sm text-mint-ink" role="status">{{ session('status') }}</div>
        @endif
        <form method="POST" action="{{ route('orders.lookup.send') }}" class="card mt-8 space-y-5 p-6">
            @csrf
            <input type="hidden" name="{{ \App\Support\SpamGuard::TIMESTAMP }}" value="{{ \App\Support\SpamGuard::token() }}">
            <div class="hidden" aria-hidden="true"><input type="text" name="{{ \App\Support\SpamGuard::HONEYPOT }}" tabindex="-1" autocomplete="off"></div>
            <x-form.input name="email" type="email" label="Email address" autocomplete="email" required />
            <x-form.input name="reference" label="Order reference" placeholder="ST-XXXX-XXXX" required class="uppercase" />
            <button type="submit" class="btn-primary w-full">Email me a secure link</button>
        </form>
        <p class="mt-6 text-center text-sm text-muted">Prefer to see all your orders? <a href="{{ route('account.login') }}" class="link">Sign in with your email</a></p>
    </div>
</x-layouts.site>
