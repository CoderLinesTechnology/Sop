@php($hero = $page?->section('hero') ?? [])
<x-layouts.site :seo="$seo">
    <section class="border-b border-line bg-[#f7f5f1]">
        <div class="container-site max-w-3xl py-14 sm:py-16">
            <p class="eyebrow">{{ $hero['eyebrow'] ?? 'Support' }}</p>
            <h1 class="display-1 mt-4">{{ $hero['title'] ?? 'How can we help?' }}</h1>
            <p class="lead mt-5">{{ $hero['text'] ?? '' }}</p>
        </div>
    </section>

    <section class="py-14 sm:py-16">
        <div class="container-site grid max-w-5xl gap-12 lg:grid-cols-[1.4fr_1fr]">
            <div class="card p-6 sm:p-8">
                @if (session('status'))
                    <div class="mb-6 flex items-start gap-3 rounded-md bg-mint px-4 py-3 text-sm text-mint-ink" role="status">
                        <x-icon name="check-circle" class="mt-0.5 size-5 shrink-0" /> {{ session('status') }}
                    </div>
                @endif
                <form method="POST" action="{{ route('contact.store') }}" class="space-y-5" novalidate>
                    @csrf
                    <input type="hidden" name="{{ \App\Support\SpamGuard::TIMESTAMP }}" value="{{ \App\Support\SpamGuard::token() }}">
                    <div class="hidden" aria-hidden="true"><label>Website <input type="text" name="{{ \App\Support\SpamGuard::HONEYPOT }}" tabindex="-1" autocomplete="off"></label></div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-form.input name="name" label="Your name" autocomplete="name" required />
                        <x-form.input name="email" type="email" label="Email address" autocomplete="email" required />
                    </div>
                    <x-form.input name="order_reference" label="Order reference" optional placeholder="e.g. ST-7KQ3-M9XD" help="Found in your confirmation email. Helps us find your order faster." />
                    <x-form.input name="subject" label="Subject" optional />
                    <x-form.textarea name="message" label="Message" rows="6" required maxlength="5000" />
                    <button type="submit" class="btn-primary w-full sm:w-auto">Send message <x-icon name="send" class="size-4" /></button>
                </form>
            </div>

            <aside class="space-y-6">
                <div>
                    <h2 class="text-[1.3rem]">Other ways to reach us</h2>
                    <p class="mt-3 flex items-center gap-2.5 text-[0.95rem]"><x-icon name="mail" class="size-5 text-brand-700" /> <a class="link" href="mailto:{{ \App\Support\Settings::supportEmail() }}">{{ \App\Support\Settings::supportEmail() }}</a></p>
                    <p class="mt-3 text-[0.88rem] text-muted">Lost your order link? <a href="{{ route('orders.lookup') }}" class="link">Find my order</a></p>
                </div>
                <div>
                    <h2 class="text-[1.15rem]">Quick answers</h2>
                    <x-ui.faq-list :faqs="$faqs" :compact="true" class="mt-4" />
                </div>
            </aside>
        </div>
    </section>
</x-layouts.site>
