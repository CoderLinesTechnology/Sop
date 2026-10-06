@php
    $endsAt = $quote->countdownEndsAt();
    $config = [
        'quote' => $quote->toPublicArray(),
        'quoteUrl' => route('checkout.quote', $order->reference),
        'coupon' => old('coupon', $coupon),
        'confirmed' => (bool) old('confirm_accuracy'),
    ];
@endphp
<x-layouts.site :seo="$seo" :scripts="['resources/js/checkout.js']" main-class="bg-[#f7f5f1]">
    <div class="container-site py-8 sm:py-12">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <a href="{{ route('checkout.review', $order->reference) }}" class="inline-flex items-center gap-2 text-[0.86rem] font-medium text-ink hover:text-brand-700"><x-icon name="arrow-left" class="size-4" /> Back</a>
            <x-checkout.stepper current="payment" />
        </div>

        <div class="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_23rem] lg:items-start">
            <form method="POST" action="{{ route('checkout.pay', $order->reference) }}" novalidate
                  x-data="paymentForm" data-config="{{ json_encode($config) }}" @submit="onSubmit" class="max-w-xl">
                @csrf
                <p class="eyebrow">Secure payment</p>
                <h1 class="display-1 mt-3">Complete your application</h1>
                <p class="lead mt-3">Your application is ready. Please complete your payment to get started.</p>

                @if ($paymentError)
                    <div class="mt-6 flex items-start gap-3 rounded-md border border-coral/40 bg-tint-rose px-4 py-3 text-sm text-tint-rose-ink" role="alert">
                        <x-icon name="alert" class="mt-0.5 size-5 shrink-0" /> {{ $paymentError }}
                    </div>
                @endif
                @error('payment')
                    <div class="mt-6 flex items-start gap-3 rounded-md border border-coral/40 bg-tint-rose px-4 py-3 text-sm text-tint-rose-ink" role="alert">
                        <x-icon name="alert" class="mt-0.5 size-5 shrink-0" /> {{ $message }}
                    </div>
                @enderror

                {{-- Price --}}
                <div class="mt-7 rounded-[10px] bg-tint-green/60 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex gap-3">
                            <x-ui.icon-badge icon="document" color="white" size="sm" />
                            <div>
                                <p class="font-semibold text-ink">{{ $order->serviceName() }}</p>
                                <p class="text-[0.86rem] text-body">{{ $order->programme ?: 'Application document' }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-[1.9rem] leading-none font-bold tracking-tight text-ink" x-text="price.total">{{ $quote->format($quote->total) }}</p>
                            <p class="mt-1 text-[0.95rem] text-muted line-through" x-show="hasDiscount()" x-text="price.original">{{ $quote->format($quote->displayOriginal()) }}</p>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center justify-end gap-2 text-[0.8rem]">
                        <span class="text-brand-700" x-show="price.promotion_label" x-text="price.promotion_label" x-cloak></span>
                        <span class="text-mint-ink" x-show="couponApplied()" x-cloak>Coupon <strong x-text="price.coupon_code"></strong>: −<span x-text="price.coupon_discount"></span></span>
                        <span class="pill-save" x-show="price.savings_percent > 0"><span>Save <span x-text="price.savings_percent">{{ $quote->savingsPercent() }}</span>%</span></span>
                    </div>
                </div>

                @if ($endsAt)
                    <div class="mt-3 flex items-center gap-3 rounded-[10px] bg-tint-green/40 px-5 py-4" data-countdown data-ends-at="{{ $endsAt->toIso8601String() }}" data-server-now="{{ now()->toIso8601String() }}">
                        <x-icon name="clock" class="size-6 shrink-0 text-brand-700" stroke="1.5" />
                        <p class="text-[0.9rem] text-ink"><span class="font-semibold">{{ $quote->promotion?->label ?: 'Limited-time offer' }}</span><br>
                            <span class="text-body">Offer ends in <span class="font-semibold text-brand-700 underline underline-offset-4 tabular-nums" data-unit="compact">—</span></span></p>
                    </div>
                @endif

                {{-- Email --}}
                <div class="mt-7">
                    <label for="email" class="field-label">Your email</label>
                    <div class="relative">
                        <x-icon name="mail" class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-muted" />
                        <input id="email" name="email" type="email" required autocomplete="email" inputmode="email" value="{{ old('email', $order->email) }}"
                               placeholder="you@example.com" class="field-input pl-11" aria-describedby="email-help" @error('email') aria-invalid="true" @enderror>
                    </div>
                    <p id="email-help" class="field-help">Where should we send your finished document? We’ll email it here after our research and writing process is complete.</p>
                    @error('email')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>

                {{-- Coupon --}}
                <div class="mt-6">
                    <button type="button" class="inline-flex items-center gap-1.5 text-[0.88rem] font-semibold text-brand-700" @click="toggleCoupon" :aria-expanded="couponOpen ? 'true' : 'false'" aria-controls="coupon-panel">
                        <x-icon name="plus" class="size-4" /> Have a coupon?
                    </button>
                    <div id="coupon-panel" class="mt-3" x-show="couponOpen" x-cloak>
                        <label for="coupon" class="sr-only">Coupon code</label>
                        <div class="flex rounded-md border border-line-strong bg-white p-1 focus-within:border-brand-600 focus-within:ring-2 focus-within:ring-brand-100">
                            <input id="coupon" name="coupon" type="text" maxlength="40" autocomplete="off" placeholder="Enter coupon code" :value="coupon" @input="onCouponInput"
                                   class="min-w-0 flex-1 border-0 bg-transparent px-3 text-[0.9rem] uppercase placeholder:normal-case focus:outline-none">
                            <button type="button" class="rounded bg-brand-700 px-4 py-2 text-[0.82rem] font-semibold text-white hover:bg-brand-800 disabled:opacity-60" @click="applyCoupon" :disabled="applying">
                                <span x-show="!applying">Apply</span><span x-show="applying" x-cloak>Checking…</span>
                            </button>
                        </div>
                        <p class="mt-2 text-[0.84rem]" role="status" x-show="couponMessage" x-text="couponMessage"
                           :class="couponState === 'success' ? 'text-mint-ink' : 'text-tint-rose-ink'" x-cloak></p>
                        <button type="button" class="mt-1 text-[0.8rem] text-muted underline underline-offset-4" x-show="couponApplied()" @click="removeCoupon" x-cloak>Remove coupon</button>
                        @error('coupon')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                </div>

                {{-- Payment method --}}
                <fieldset class="mt-7">
                    <legend class="field-label">Payment method</legend>
                    <label class="flex items-center gap-4 rounded-[10px] border border-brand-600 bg-white px-4 py-4 ring-1 ring-brand-600">
                        <input type="radio" name="payment_method" value="paystack" checked class="size-5 border-brand-700 text-brand-700 focus:ring-brand-600">
                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-md bg-[#0BA4DB]/10" aria-hidden="true">
                            <svg viewBox="0 0 24 24" class="size-5"><rect x="3" y="4" width="18" height="3" rx="1" fill="#0BA4DB"/><rect x="3" y="9" width="18" height="3" rx="1" fill="#0BA4DB"/><rect x="3" y="14" width="13" height="3" rx="1" fill="#011B33"/><rect x="3" y="19" width="18" height="2" rx="1" fill="#0BA4DB"/></svg>
                        </span>
                        <span><span class="block font-semibold text-ink">Paystack</span><span class="block text-[0.8rem] text-muted">Visa, Mastercard, Verve, Bank Transfer and more</span></span>
                    </label>
                    <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 px-1" aria-label="Accepted payment methods">
                        <span class="font-sans text-[1.15rem] font-extrabold tracking-tight text-[#1A1F71] italic">VISA</span>
                        <svg viewBox="0 0 38 24" class="h-6" role="img" aria-label="Mastercard"><circle cx="15" cy="12" r="9" fill="#EB001B"/><circle cx="23" cy="12" r="9" fill="#F79E1B"/><path d="M19 4.9a9 9 0 0 1 0 14.2 9 9 0 0 1 0-14.2z" fill="#FF5F00"/></svg>
                        <span class="font-sans text-[1.05rem] font-bold text-[#00425F]"><span class="text-[#E31B23]">V</span>erve</span>
                        <span class="inline-flex items-center gap-1.5 text-[0.8rem] text-body"><x-icon name="bank" class="size-4" /> Bank Transfer</span>
                    </div>
                </fieldset>

                {{-- Confirmation --}}
                <div class="mt-7 space-y-3">
                    <label class="flex items-start gap-3 text-[0.9rem] text-ink">
                        <input id="confirm_accuracy" type="checkbox" name="confirm_accuracy" value="1" @checked(old('confirm_accuracy')) @change="onConfirm"
                               class="mt-0.5 size-5 rounded border-line-strong text-brand-700 focus:ring-brand-600" @error('confirm_accuracy') aria-invalid="true" @enderror>
                        <span>I confirm that the information I’ve provided is accurate.</span>
                    </label>
                    @error('confirm_accuracy')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    <label class="flex items-start gap-3 text-[0.9rem] text-ink">
                        <input type="hidden" name="create_account" value="0">
                        <input type="checkbox" name="create_account" value="1" @checked(old('create_account', $order->create_account)) class="mt-0.5 size-5 rounded border-line-strong text-brand-700 focus:ring-brand-600">
                        <span>Create an account to save your applications and access previous orders. <span class="text-muted">(optional — no password needed)</span></span>
                    </label>
                </div>

                <button type="submit" class="btn-primary mt-7 min-h-13 w-full text-base" :disabled="submitting || !confirmed">
                    <x-icon name="lock" class="size-4" />
                    <span x-show="!submitting">Pay <span x-text="price.total">{{ $quote->format($quote->total) }}</span></span>
                    <span x-show="submitting" x-cloak>Redirecting to secure payment…</span>
                    <x-icon name="arrow-right" class="size-4" />
                </button>
                <p class="mt-3 text-center text-[0.78rem] text-muted">By paying, you agree to our <a class="underline underline-offset-2" href="{{ route('pages.show', 'terms') }}">Terms</a> and <a class="underline underline-offset-2" href="{{ route('pages.show', 'refund-policy') }}">Refund Policy</a>.</p>

                <div class="mt-6 flex items-start gap-3 text-[0.84rem] text-body">
                    <x-icon name="lock" class="mt-0.5 size-5 shrink-0 text-ink" stroke="1.5" />
                    <p><span class="font-semibold text-ink">Secure payment</span><br>Your payment information is processed securely by Paystack. We never see or store your card details.</p>
                </div>

                <div class="mt-6 flex items-start gap-3 border-t border-line pt-6 text-[0.86rem]">
                    <x-icon name="chat" class="mt-0.5 size-5 shrink-0 text-ink" stroke="1.5" />
                    <p class="text-ink">Need help?<br><a href="{{ route('contact.show') }}" class="link">Contact support</a></p>
                </div>
            </form>

            <aside class="lg:sticky lg:top-24">
                <x-checkout.summary :order="$order" :country-name="$countryName" :editable="true" />
            </aside>
        </div>
    </div>
</x-layouts.site>
