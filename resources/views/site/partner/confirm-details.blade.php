<x-layouts.site :seo="$seo" main-class="bg-[#f7f5f1]">
    <div class="container-site max-w-lg py-16">
        <p class="eyebrow">Referral partners</p>
        @if ($confirmed)
            <h1 class="display-2 mt-3">Payment details confirmed</h1>
            <div class="mt-6 flex items-start gap-3 rounded-md bg-mint px-4 py-3 text-sm text-mint-ink" role="status">
                <x-icon name="check-circle" class="mt-0.5 size-5 shrink-0" /> Payouts will now be sent to {{ $current }}.
            </div>
            <p class="mt-6 text-[0.9rem] text-body">You can close this page. Your dashboard shows the new details.</p>
        @elseif ($pending)
            <h1 class="display-2 mt-3">Confirm your payment details</h1>
            <p class="lead mt-3">Your partner payouts will be sent to:</p>
            <p class="card mt-4 px-5 py-4 text-base font-semibold text-ink">{{ $pending }}</p>
            <form method="POST" action="{{ route('partner.details.apply', $token) }}" class="mt-6">
                @csrf
                <button type="submit" class="btn-primary w-full">Confirm these details</button>
            </form>
            <p class="mt-4 text-[0.86rem] text-muted">Didn’t ask for this? Don’t confirm: your current details stay as they are. Please tell us at <a href="mailto:{{ \App\Support\Settings::supportEmail() }}" class="link">{{ \App\Support\Settings::supportEmail() }}</a>.</p>
        @else
            <h1 class="display-2 mt-3">This link has expired</h1>
            <p class="lead mt-3">The confirmation link is no longer valid: it was already used or is more than 48 hours old. You can enter your details again on your dashboard.</p>
            <p class="mt-6 text-sm text-muted">Lost your dashboard link? <a href="{{ route('partner.request') }}" class="link">Email it to me again</a></p>
        @endif
    </div>
</x-layouts.site>
