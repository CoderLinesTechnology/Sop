<x-layouts.site :seo="$seo" main-class="bg-[#f7f5f1]">
    <div class="container-site max-w-lg py-16">
        <h1 class="display-2">Sign in</h1>
        <p class="lead mt-3">No password needed. Enter your email and we’ll send you a secure sign-in link to see all your orders.</p>
        @if (session('status'))
            <div class="mt-6 rounded-md bg-mint px-4 py-3 text-sm text-mint-ink" role="status">{{ session('status') }}</div>
        @endif
        <form method="POST" action="{{ route('account.send-link') }}" class="card mt-8 space-y-5 p-6">
            @csrf
            <input type="hidden" name="{{ \App\Support\SpamGuard::TIMESTAMP }}" value="{{ \App\Support\SpamGuard::token() }}">
            <div class="hidden" aria-hidden="true"><input type="text" name="{{ \App\Support\SpamGuard::HONEYPOT }}" tabindex="-1" autocomplete="off"></div>
            <x-form.input name="email" type="email" label="Email address" autocomplete="email" required />
            <button type="submit" class="btn-primary w-full">Email me a sign-in link</button>
        </form>
        <p class="mt-6 text-center text-sm text-muted">You never need an account to order. <a href="{{ route('order.start') }}" class="link">Start an application</a></p>
    </div>
</x-layouts.site>
