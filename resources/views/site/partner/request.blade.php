<x-layouts.site :seo="$seo" main-class="bg-[#f7f5f1]">
    <div class="container-site max-w-lg py-16">
        <p class="eyebrow">Referral partners</p>
        <h1 class="display-2 mt-3">Your referral dashboard</h1>
        <p class="lead mt-3">Your dashboard has a private link, so there is no password. Enter the email address we have for you and we’ll email the link again.</p>
        @if (session('status'))
            <div class="mt-6 rounded-md bg-mint px-4 py-3 text-sm text-mint-ink" role="status">{{ session('status') }}</div>
        @endif
        <form method="POST" action="{{ route('partner.send') }}" class="card mt-8 space-y-5 p-6">
            @csrf
            <input type="hidden" name="{{ \App\Support\SpamGuard::TIMESTAMP }}" value="{{ \App\Support\SpamGuard::token() }}">
            <div class="hidden" aria-hidden="true"><input type="text" name="{{ \App\Support\SpamGuard::HONEYPOT }}" tabindex="-1" autocomplete="off"></div>
            <x-form.input name="email" type="email" label="Email address" autocomplete="email" required />
            <button type="submit" class="btn-primary w-full">Email me my dashboard link</button>
        </form>
        <p class="mt-6 text-center text-sm text-muted">Want to become a referral partner? <a href="{{ route('contact.show') }}" class="link">Get in touch</a></p>
    </div>
</x-layouts.site>
