{{-- Referral consent prompt: small, non-blocking, works without JavaScript (plain POST); Alpine (CSP build) just hides it in place. --}}
@vite(['resources/js/checkout.js'])
<section x-data="referralConsent" x-show="open" role="region" aria-labelledby="referral-consent-title"
         class="fixed inset-x-3 bottom-3 z-40 sm:inset-x-auto sm:right-5 sm:bottom-5 sm:w-[24rem]">
    <div class="card p-4 shadow-lg sm:p-5">
        <h2 id="referral-consent-title" class="font-sans text-[0.95rem] font-semibold text-ink">A {{ $siteName }} partner referred you</h2>
        <p class="mt-1.5 text-[0.86rem] leading-relaxed text-body">
            May we remember this for {{ $days }} {{ str('day')->plural($days) }}, so they are credited if you order later?
            <a href="{{ route('pages.show', 'privacy-policy') }}" class="link font-medium">Privacy notice</a>
        </p>
        <div class="mt-3 flex gap-2">
            <form method="POST" action="{{ route('referral.consent') }}" class="flex-1" @submit.prevent="decide">
                @csrf
                <input type="hidden" name="decision" value="remember">
                <button type="submit" class="btn-primary w-full px-3 text-[0.88rem]">Remember</button>
            </form>
            <form method="POST" action="{{ route('referral.consent') }}" class="flex-1" @submit.prevent="decide">
                @csrf
                <input type="hidden" name="decision" value="decline">
                <button type="submit" class="btn-outline w-full px-3 text-[0.88rem]">No thanks</button>
            </form>
        </div>
    </div>
</section>
