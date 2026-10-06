@props(['section' => []])
<section class="container-site py-4" aria-labelledby="newsletter-heading">
    <div class="grid gap-8 rounded-[10px] bg-brand-800 px-6 py-9 text-white sm:px-10 lg:grid-cols-[1.25fr_1.2fr_auto] lg:items-center lg:gap-10">
        <div>
            <p class="text-[0.7rem] font-semibold tracking-[0.18em] text-white/75 uppercase">{{ $section['eyebrow'] ?? 'Stay informed' }}</p>
            <h2 id="newsletter-heading" class="mt-2 text-[1.6rem] text-white">{{ $section['title'] ?? 'Get helpful tips and updates.' }}</h2>
            <p class="mt-2 text-[0.88rem] text-white/80">{{ $section['text'] ?? '' }}</p>
        </div>
        <form method="POST" action="{{ route('newsletter.subscribe') }}" class="w-full">
            @csrf
            <input type="hidden" name="source" value="resources">
            <input type="hidden" name="{{ \App\Support\SpamGuard::TIMESTAMP }}" value="{{ \App\Support\SpamGuard::token() }}">
            <div class="hidden" aria-hidden="true"><input type="text" name="{{ \App\Support\SpamGuard::HONEYPOT }}" tabindex="-1" autocomplete="off"></div>
            <div class="flex flex-col gap-2 sm:flex-row sm:gap-0 sm:rounded-md sm:bg-white sm:p-1">
                <label for="newsletter-email" class="sr-only">Email address</label>
                <div class="flex flex-1 items-center gap-2 rounded-md bg-white px-3 sm:rounded-none">
                    <x-icon name="mail" class="size-4 shrink-0 text-muted" />
                    <input id="newsletter-email" name="email" type="email" required autocomplete="email" placeholder="Enter your email address"
                           class="min-h-11 w-full border-0 bg-transparent text-[0.9rem] text-ink placeholder:text-muted/70 focus:outline-none">
                </div>
                <button type="submit" class="btn-primary min-h-11 border border-white/20">Subscribe <x-icon name="arrow-right" class="size-4" /></button>
            </div>
            @if (session('newsletter_status'))
                <p class="mt-3 text-sm text-white" role="status">{{ session('newsletter_status') }}</p>
            @endif
            @error('email')
                <p class="mt-3 text-sm text-[#ffd2cf]" role="alert">{{ $message }}</p>
            @enderror
        </form>
        <p class="flex items-center gap-3 text-[0.82rem] text-white/80 lg:border-l lg:border-white/15 lg:pl-8">
            <x-icon name="send" class="size-6 text-white" stroke="1.4" />
            <span>{!! nl2br(e($section['note'] ?? "No spam.\nJust useful content.")) !!}</span>
        </p>
    </div>
</section>
