@php
    use App\Enums\OrderStatus;
    $status = $order->status;
    $isDelivered = $status === OrderStatus::Delivered || ($order->delivered_at && in_array($status, [OrderStatus::PartiallyRefunded], true));
    $processing = $status->isProcessing() || in_array($status, [OrderStatus::ProcessingFailed, OrderStatus::ManualReview, OrderStatus::DeliveryFailed], true);
    $needsInfo = $status === OrderStatus::NeedsInformation && $informationRequest;
    $config = ['status' => $status->value, 'steps' => $steps, 'poll' => $processing, 'progressUrl' => route('orders.progress', $order->public_id)];
    $estimate = $order->service?->deliveryLabel() ?? '20–30 minutes';
@endphp
<x-layouts.site :seo="$seo" :scripts="['resources/js/checkout.js']" main-class="bg-[#f7f5f1]">
    <div class="container-site py-8 sm:py-12">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <p class="text-[0.86rem] text-muted">Order <span class="font-semibold text-ink">{{ $order->reference }}</span></p>
            <x-checkout.stepper current="done" :with-done="true" />
        </div>

        @if (session('status'))
            <div class="mt-6 flex items-start gap-3 rounded-md bg-mint px-4 py-3 text-sm text-mint-ink" role="status"><x-icon name="check-circle" class="mt-0.5 size-5 shrink-0" /> {{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_21rem] lg:items-start">
            <div class="space-y-6">
                @if ($isDelivered && $version)
                    {{-- Delivered --}}
                    <section class="card p-6 sm:p-8" aria-labelledby="ready-heading">
                        <x-ui.icon-badge icon="check" size="lg" />
                        <h1 id="ready-heading" class="display-2 mt-5">Your document is ready</h1>
                        <p class="lead mt-3">Your Statementra document has been completed. We’ve also sent both files to <strong class="text-ink">{{ $order->email }}</strong>.</p>
                        <div class="mt-6 rounded-[10px] border border-line bg-cream/60 p-5">
                            <p class="font-serif text-[1.25rem] text-ink">{{ $order->serviceName() }}</p>
                            <p class="mt-1 text-[0.9rem] text-body">Prepared for: {{ $order->applicationTitle() }}</p>
                            <p class="mt-1 text-[0.8rem] text-muted">{{ number_format($version->word_count) }} words · Version {{ $version->version_number }}</p>
                        </div>
                        <div class="mt-6 grid gap-3 sm:grid-cols-3">
                            <a href="{{ route('orders.download', [$order->public_id, $version->uuid, 'pdf']) }}?inline=1" target="_blank" rel="noopener" class="btn-primary"><x-icon name="eye" class="size-4" /> View PDF</a>
                            <a href="{{ route('orders.download', [$order->public_id, $version->uuid, 'pdf']) }}" class="btn-outline"><x-icon name="download" class="size-4" /> Download PDF</a>
                            <a href="{{ route('orders.download', [$order->public_id, $version->uuid, 'docx']) }}" class="btn-outline"><x-icon name="download" class="size-4" /> Word Document</a>
                        </div>
                    </section>

                    {{-- Revision --}}
                    <section class="card p-6 sm:p-8" aria-labelledby="revision-heading">
                        <h2 id="revision-heading" class="text-[1.4rem]">Request a revision</h2>
                        @if ($openRevision)
                            <p class="mt-3 text-[0.92rem] text-body">Revision #{{ $openRevision->number }} is {{ strtolower($openRevision->status->getLabel()) }}. We’ll email you when it’s ready.</p>
                        @elseif ($revisionEligibility['allowed'])
                            <p class="mt-2 text-[0.9rem] text-body">
                                @if ($revisionEligibility['fee'] > 0)
                                    Additional revisions cost {{ \App\Support\Money::format($revisionEligibility['fee'], $order->currency) }}.
                                @else
                                    {{ $revisionEligibility['included_remaining'] }} revision{{ $revisionEligibility['included_remaining'] === 1 ? '' : 's' }} included
                                    @if ($order->revision_deadline_at) until {{ $order->revision_deadline_at->format('j F Y') }}@endif.
                                @endif
                                Tell us what you would like us to change.
                            </p>
                            <form method="POST" action="{{ route('orders.revisions.store', $order->public_id) }}" class="mt-4 space-y-4">
                                @csrf
                                <x-form.textarea name="request_text" label="What would you like us to change?" rows="5" required maxlength="5000"
                                                 placeholder="e.g. Please put more emphasis on my research project and shorten the introduction." />
                                <button type="submit" class="btn-outline">{{ $revisionEligibility['fee'] > 0 ? 'Pay & request revision' : 'Submit revision request' }}</button>
                            </form>
                        @else
                            <p class="mt-3 text-[0.92rem] text-muted">{{ $revisionEligibility['reason'] }}</p>
                        @endif
                    </section>

                    {{-- Feedback --}}
                    <section class="card p-6 sm:p-8" aria-labelledby="feedback-heading">
                        <h2 id="feedback-heading" class="text-[1.4rem]">How satisfied are you?</h2>
                        @if ($order->feedback)
                            <p class="mt-3 text-[0.92rem] text-body">Thank you for your feedback.</p>
                            <x-ui.stars :rating="$order->feedback->rating" class="mt-2" />
                        @else
                            <form method="POST" action="{{ route('orders.feedback', $order->public_id) }}" class="mt-4 space-y-4" x-data="starRating" data-rating="{{ old('rating', 0) }}">
                                @csrf
                                <fieldset>
                                    <legend class="sr-only">Rating</legend>
                                    <div class="flex gap-1" @mouseleave="clearPreview">
                                        @for ($i = 1; $i <= 5; $i++)
                                            <label class="cursor-pointer" @mouseenter="preview({{ $i }})">
                                                <input type="radio" name="rating" value="{{ $i }}" class="peer sr-only" @click="set({{ $i }})" @checked((int) old('rating') === $i) required>
                                                <span class="inline-flex rounded p-1 text-line-strong peer-focus-visible:ring-2 peer-focus-visible:ring-brand-600" :class="isActive({{ $i }}) ? 'text-[#E2A33B]' : 'text-line-strong'">
                                                    <x-icon name="star-solid" class="size-8" />
                                                </span>
                                                <span class="sr-only">{{ $i }} star{{ $i > 1 ? 's' : '' }}</span>
                                            </label>
                                        @endfor
                                    </div>
                                    @error('rating')<p class="field-error" role="alert">Please choose a rating.</p>@enderror
                                </fieldset>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <x-form.textarea name="liked" label="What did you like?" optional rows="3" maxlength="2000" />
                                    <x-form.textarea name="improve" label="What should be improved?" optional rows="3" maxlength="2000" />
                                </div>
                                <button type="submit" class="btn-outline">Send feedback</button>
                            </form>
                        @endif
                    </section>
                @elseif ($needsInfo)
                    {{-- Follow-up questions --}}
                    <section class="card p-6 sm:p-8" aria-labelledby="info-heading">
                        <x-ui.icon-badge icon="message" color="yellow" size="lg" />
                        <h1 id="info-heading" class="display-2 mt-5">We need one more detail to make your document stronger.</h1>
                        <p class="lead mt-3">We never invent information, so a quick answer helps us write something accurate and specific to you.</p>
                        <form method="POST" action="{{ route('orders.information', $order->public_id) }}" class="mt-6 space-y-5">
                            @csrf
                            @foreach ($informationRequest->questions as $question)
                                <div>
                                    <x-form.textarea :name="'answers['.$question['key'].']'" :label="$question['question']" rows="3" maxlength="3000" :help="$question['why'] ?: null" />
                                </div>
                            @endforeach
                            @error('answers')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            <button type="submit" class="btn-primary">Send answer & continue <x-icon name="arrow-right" class="size-4" /></button>
                        </form>
                    </section>
                @elseif (in_array($status, [OrderStatus::Cancelled, OrderStatus::Refunded], true))
                    <section class="card p-6 sm:p-8">
                        <h1 class="display-2">This order is {{ strtolower($status->customerLabel()) }}</h1>
                        <p class="lead mt-3">If you have any questions, please contact our support team and quote your order reference {{ $order->reference }}.</p>
                        <a href="{{ route('contact.show') }}" class="btn-outline mt-6">Contact support</a>
                    </section>
                @else
                    {{-- Processing --}}
                    <section class="card p-6 sm:p-8" aria-labelledby="progress-heading" x-data="orderStatus" data-config="{{ json_encode($config) }}">
                        <p class="inline-flex items-center gap-2 rounded-full bg-mint px-3 py-1 text-[0.8rem] font-semibold text-mint-ink"><x-icon name="check" class="size-3.5" stroke="2.5" /> Payment confirmed</p>
                        <h1 id="progress-heading" class="display-2 mt-5">We’re working on your document.</h1>
                        <p class="lead mt-3">We’re researching your application, reviewing your background, verifying relevant information and preparing your personalized document.</p>

                        <ol class="mt-7 space-y-3.5" aria-label="Progress">
                            <template x-for="step in steps" :key="step.key">
                                <li class="flex items-center gap-3">
                                    <span class="inline-flex size-6 shrink-0 items-center justify-center rounded-full border-2" :class="stepClass(step)">
                                        <svg x-show="isDone(step)" viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                        <span x-show="isCurrent(step)" class="size-2 animate-pulse rounded-full bg-brand-700"></span>
                                    </span>
                                    <span class="text-[0.95rem]" :class="isDone(step) ? 'text-ink' : (isCurrent(step) ? 'font-semibold text-brand-700' : 'text-muted')" x-text="step.label"></span>
                                </li>
                            </template>
                            <noscript>
                                @foreach ($steps as $step)
                                    <li>{{ $step['state'] === 'done' ? '✓' : ($step['state'] === 'current' ? '●' : '○') }} {{ $step['label'] }}</li>
                                @endforeach
                            </noscript>
                        </ol>

                        <div class="mt-8 grid gap-4 rounded-[10px] bg-cream/70 p-5 sm:grid-cols-2">
                            <div>
                                <p class="text-[0.78rem] text-muted">Estimated delivery</p>
                                <p class="mt-1 font-semibold text-ink">{{ in_array($status, [OrderStatus::ProcessingFailed, OrderStatus::ManualReview, OrderStatus::DeliveryFailed], true) || $order->delay_notified_at ? 'Taking a little longer than usual' : 'Approximately '.$estimate }}</p>
                            </div>
                            <div>
                                <p class="text-[0.78rem] text-muted">We’ll send your document to</p>
                                <p class="mt-1 font-semibold break-all text-ink">{{ $order->email }}</p>
                            </div>
                        </div>
                        <p class="mt-5 flex items-start gap-3 text-[0.88rem] text-body"><x-icon name="info" class="mt-0.5 size-5 shrink-0 text-brand-700" /> You don’t need to stay on this page. We’ll email you when your document is ready.</p>
                    </section>
                @endif
            </div>

            <aside class="space-y-4 lg:sticky lg:top-24">
                <x-checkout.summary :order="$order" :country-name="\App\Support\Countries::name($order->country_code)" />
                <div class="card p-5 text-[0.86rem]">
                    <p class="font-semibold text-ink">Need help?</p>
                    <p class="mt-1 text-body">Quote your order reference <strong>{{ $order->reference }}</strong>.</p>
                    <a href="{{ route('contact.show') }}" class="link mt-2 inline-block">Contact support</a>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.site>
