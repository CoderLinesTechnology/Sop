@php($editBase = route('order.start', $order->service->slug).'?order='.$order->reference)
<x-layouts.site :seo="$seo" main-class="bg-[#f7f5f1]">
    <div class="container-site py-8 sm:py-12">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <a href="{{ $editBase }}" class="inline-flex items-center gap-2 text-[0.86rem] font-medium text-ink hover:text-brand-700"><x-icon name="arrow-left" class="size-4" /> Back</a>
            <x-checkout.stepper current="review" :with-done="true" />
        </div>

        <h1 class="display-1 mt-8">Review your application.</h1>
        <p class="lead mt-3 max-w-xl">Please check your details below. Everything looks good? You’re all set to proceed to payment.</p>

        <div class="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_21rem] lg:items-start">
            <div class="space-y-4">
                @foreach ($sections as $key => $section)
                    <section class="card p-5 sm:p-6" aria-labelledby="review-{{ $key }}">
                        <div class="flex items-center justify-between gap-4 border-b border-line pb-4">
                            <div class="flex items-center gap-3">
                                <x-ui.icon-badge :icon="$section['icon']" size="sm" />
                                <h2 id="review-{{ $key }}" class="text-[1.2rem]">{{ $section['title'] }}</h2>
                            </div>
                            <a href="{{ $editBase }}" class="inline-flex items-center gap-1.5 text-[0.8rem] font-semibold text-ink hover:text-brand-700"><x-icon name="pencil" class="size-3.5" /> Edit</a>
                        </div>
                        <dl class="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-2">
                            @foreach ($section['items'] as $item)
                                <div @class(['sm:col-span-2' => $item['long'] && $key !== 'additional'])>
                                    <dt class="text-[0.8rem] {{ $key === 'additional' ? 'font-semibold text-ink' : 'text-muted' }}">{{ $item['label'] }}</dt>
                                    <dd class="mt-1 text-[0.92rem] whitespace-pre-line {{ $key === 'additional' ? 'text-body' : 'text-ink' }}">{{ $item['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>

                    @if ($key === 'application')
                        <section class="card p-5 sm:p-6" aria-labelledby="review-files">
                            <div class="flex items-center justify-between gap-4 border-b border-line pb-4">
                                <div class="flex items-center gap-3">
                                    <x-ui.icon-badge icon="document" size="sm" />
                                    <h2 id="review-files" class="text-[1.2rem]">Uploaded Documents</h2>
                                </div>
                                <a href="{{ $editBase }}" class="inline-flex items-center gap-1.5 text-[0.8rem] font-semibold text-ink hover:text-brand-700"><x-icon name="pencil" class="size-3.5" /> Edit</a>
                            </div>
                            @if ($order->files->isNotEmpty())
                                <ul class="mt-4 flex flex-wrap gap-2.5">
                                    @foreach ($order->files as $file)
                                        <li class="inline-flex items-center gap-2 rounded-md border border-line px-3 py-2 text-[0.84rem] text-ink">
                                            <x-icon name="document" class="size-4 text-muted" stroke="1.5" /> {{ $file->original_name }}
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="mt-4 text-[0.88rem] text-muted">No documents uploaded — that’s fine. You can add a CV or programme requirements by editing your application.</p>
                            @endif
                        </section>
                    @endif
                @endforeach

                <p class="flex items-start gap-3 rounded-[10px] bg-tint-green/70 px-4 py-3 text-[0.82rem] text-tint-green-ink">
                    <x-icon name="shield-check" class="mt-0.5 size-5 shrink-0" /> <span><strong class="font-semibold">Your information is secure.</strong> We use industry-standard security to keep your data safe and private.</span>
                </p>

                <div class="flex items-center justify-between gap-4 pt-2">
                    <a href="{{ $editBase }}" class="inline-flex items-center gap-2 text-[0.86rem] font-medium text-ink hover:text-brand-700"><x-icon name="arrow-left" class="size-4" /> Back</a>
                    <a href="{{ route('checkout.payment', $order->reference) }}" class="btn-primary">Continue to Payment <x-icon name="arrow-right" class="size-4" /></a>
                </div>
            </div>

            <aside class="lg:sticky lg:top-24" aria-label="Order summary">
                <div class="card p-5 sm:p-6">
                    <x-ui.icon-badge icon="document" />
                    <h2 class="mt-3 text-[1.45rem]">Your Application</h2>
                    <dl class="mt-5 space-y-4 border-t border-line pt-5 text-[0.86rem]">
                        <div><dt class="text-muted">Service</dt><dd class="mt-0.5 font-medium text-ink">{{ $order->serviceName() }}</dd></div>
                        @if ($order->institution)<div><dt class="text-muted">University</dt><dd class="mt-0.5 font-medium text-ink">{{ $order->institution }}</dd></div>@endif
                        @if ($order->programme)<div><dt class="text-muted">Programme</dt><dd class="mt-0.5 font-medium text-ink">{{ $order->programme }}</dd></div>@endif
                        <div><dt class="text-muted">Delivery Time</dt><dd class="mt-0.5 font-medium text-ink">{{ $order->service->deliveryLabel() }}</dd></div>
                    </dl>
                    <div class="mt-5 border-t border-line pt-5">
                        <p class="text-[0.82rem] font-semibold text-ink">Estimated Total</p>
                        <div class="mt-2 flex items-center gap-3">
                            <span class="text-[2rem] leading-none font-bold tracking-tight text-ink">{{ $quote->format($quote->total) }}</span>
                            @if ($quote->savingsPercent() > 0)<span class="pill-save">Save {{ $quote->savingsPercent() }}%</span>@endif
                        </div>
                        @if ($quote->displayOriginal())<p class="mt-1.5 text-[1.05rem] text-muted line-through">{{ $quote->format($quote->displayOriginal()) }}</p>@endif
                    </div>
                    <div class="mt-5 flex items-start gap-3 rounded-[10px] bg-tint-green/70 px-4 py-3.5 text-[0.8rem] text-tint-green-ink">
                        <x-icon name="lock" class="mt-0.5 size-5 shrink-0" />
                        <span><strong class="block font-semibold">Secure payment</strong>Your payment information is processed securely by Paystack.</span>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.site>
