@php
    $statusClasses = [
        'Paid' => 'bg-mint text-mint-ink',
        'Partially refunded' => 'bg-sand text-ink',
        'Refunded' => 'bg-tint-rose text-tint-rose-ink',
    ];
    $payoutClasses = [
        'Requested' => 'bg-sand text-ink',
        'Paid' => 'bg-mint text-mint-ink',
        'Rejected' => 'bg-tint-rose text-tint-rose-ink',
        'Cancelled' => 'bg-sand text-muted',
    ];
@endphp
<x-layouts.site :seo="$seo" :scripts="['resources/js/checkout.js']" main-class="bg-[#f7f5f1]">
    <div class="container-site max-w-4xl py-10 sm:py-14">
        <p class="eyebrow">Referral partner</p>
        <h1 class="display-2 mt-3">Hi {{ $name }}</h1>
        <p class="lead mt-3">Your private referral dashboard. Keep this page’s address to yourself: anyone who has it can see these figures.</p>

        @if (session('partner_status'))
            <div class="mt-6 flex items-start gap-3 rounded-md bg-mint px-4 py-3 text-sm text-mint-ink" role="status"><x-icon name="check-circle" class="mt-0.5 size-5 shrink-0" /> {{ session('partner_status') }}</div>
        @endif
        @error('payout')
            <div class="mt-6 flex items-start gap-3 rounded-md border border-coral/40 bg-tint-rose px-4 py-3 text-sm text-tint-rose-ink" role="alert"><x-icon name="alert" class="mt-0.5 size-5 shrink-0" /> {{ $message }}</div>
        @enderror

        {{-- Share --}}
        <section class="card mt-8 p-5 sm:p-6" aria-labelledby="share-heading">
            <h2 id="share-heading" class="text-lg font-semibold text-ink">Your referral link</h2>
            <p class="mt-1 text-[0.9rem] text-body">Share this link. Whichever page people open, your code stays with them for {{ $cookieDays }} {{ str('day')->plural($cookieDays) }}, so their order counts for you. They can also type your code when they pay.</p>

            <div x-data="copyText" class="mt-4 flex flex-col gap-2 sm:flex-row">
                <label for="share-link" class="sr-only">Your referral link</label>
                <input id="share-link" x-ref="field" type="text" readonly value="{{ $shareUrl }}" class="field-input min-w-0 font-mono text-[0.86rem]">
                <button type="button" class="btn-primary shrink-0" @click="copy">
                    <span x-show="!copied">Copy link</span><span x-show="copied" x-cloak>Copied</span>
                </button>
                <span class="sr-only" role="status" aria-live="polite" x-text="statusText()"></span>
            </div>

            <dl class="mt-5 grid grid-cols-1 gap-4 text-[0.9rem] sm:grid-cols-2">
                <div>
                    <dt class="text-muted">Your code</dt>
                    <dd class="mt-0.5 font-mono text-base font-semibold text-ink">{{ $code }}</dd>
                </div>
                <div>
                    <dt class="text-muted">Your commission</dt>
                    <dd class="mt-0.5 font-semibold text-ink">{{ $rate }} <span class="font-normal text-body">of what your referrals pay</span></dd>
                </div>
            </dl>
        </section>

        {{-- Results --}}
        <section class="mt-8" aria-labelledby="results-heading">
            <h2 id="results-heading" class="text-lg font-semibold text-ink">Results</h2>
            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ([
                    ['Visits, last 30 days', $summary['visits_30_days']],
                    ['Visits, all time', $summary['visits_total']],
                    ['Orders started', $summary['orders_started']],
                    ['Paid orders', $summary['paid_orders']],
                ] as [$label, $value])
                    <div class="card p-4">
                        <dt class="text-[0.8rem] text-muted">{{ $label }}</dt>
                        <dd class="mt-1 text-2xl font-bold tracking-tight text-ink tabular-nums">{{ number_format($value) }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-2 text-xs text-muted">A visit is one person following your link on a given day. “Orders started” counts orders that reached payment.</p>
        </section>

        {{-- Commission and payouts --}}
        <section id="payouts" class="card mt-8 scroll-mt-24 p-5 sm:p-6" aria-labelledby="commission-heading">
            <h2 id="commission-heading" class="text-lg font-semibold text-ink">Commission and payouts</h2>
            @forelse ($summary['money'] as $money)
                @if (count($summary['money']) > 1)
                    <p class="mt-4 text-[0.8rem] font-semibold tracking-wide text-muted uppercase">{{ $money['currency'] }}</p>
                @endif
                <dl class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <dt class="text-[0.8rem] text-muted">Pending</dt>
                        <dd class="mt-0.5 font-semibold text-ink tabular-nums">{{ $money['pending'] }}</dd>
                        @if ($money['next_available'])
                            <dd class="text-[0.75rem] text-muted">Next available {{ $money['next_available'] }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-[0.8rem] text-muted">Available</dt>
                        <dd class="mt-0.5 font-bold text-brand-700 tabular-nums">{{ $money['available'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[0.8rem] text-muted">Requested</dt>
                        <dd class="mt-0.5 font-semibold text-ink tabular-nums">{{ $money['requested'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[0.8rem] text-muted">Paid</dt>
                        <dd class="mt-0.5 font-semibold text-ink tabular-nums">{{ $money['paid'] }}</dd>
                    </div>
                </dl>
                <p class="mt-2 text-[0.8rem] text-muted">Referred revenue {{ $money['revenue'] }} · commission earned {{ $money['earned'] }}</p>
            @empty
                <p class="mt-2 text-[0.9rem] text-body">No commission yet. It appears here as soon as someone you referred pays for an order.</p>
            @endforelse

            <div class="mt-5 border-t border-line pt-5">
                @if ($eligibility['allowed'])
                    <form method="POST" action="{{ route('partner.payouts.request', $token) }}">
                        @csrf
                        <button type="submit" class="btn-primary w-full sm:w-auto">Request payout of {{ \App\Support\Money::format($eligibility['available'], $eligibility['currency'], true) }}</button>
                    </form>
                    <p class="mt-2 text-[0.84rem] text-body">We pay your whole available balance to {{ $paymentDetails }}.</p>
                @else
                    <button type="button" class="btn-primary w-full sm:w-auto" disabled aria-describedby="payout-help">Request payout</button>
                    <p id="payout-help" class="mt-2 text-[0.84rem] text-body">{{ $eligibility['reason'] }}</p>
                @endif
            </div>
            <p class="mt-4 text-xs text-muted">Commission becomes available {{ $holdDays }} {{ str('day')->plural($holdDays) }} after the payment it comes from (the refund and revision window). Refunds reduce commission in proportion to the amount refunded.</p>
        </section>

        {{-- Payout history --}}
        @if ($payouts !== [])
            <section class="card mt-8" aria-labelledby="history-heading">
                <h2 id="history-heading" class="px-5 pt-5 text-lg font-semibold text-ink sm:px-6">Payout requests</h2>
                <ul class="mt-3 divide-y divide-line border-t border-line">
                    @foreach ($payouts as $payout)
                        <li class="flex flex-wrap items-start justify-between gap-3 px-5 py-4 sm:px-6">
                            <div class="min-w-0">
                                <p class="font-semibold text-ink tabular-nums">{{ $payout['amount'] }}</p>
                                <p class="text-[0.82rem] text-muted">Requested {{ $payout['date'] }}@if ($payout['reference']) · Reference {{ $payout['reference'] }}@endif</p>
                                @if ($payout['reason'])
                                    <p class="mt-1 text-[0.84rem] text-body">{{ $payout['reason'] }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-[0.75rem] font-medium {{ $payoutClasses[$payout['status']] ?? 'bg-sand text-ink' }}">{{ $payout['status'] }}</span>
                                @if ($payout['open'])
                                    <form method="POST" action="{{ route('partner.payouts.cancel', [$token, $payout['id']]) }}">
                                        @csrf
                                        <button type="submit" class="text-[0.84rem] font-semibold text-tint-rose-ink underline underline-offset-4">Cancel request</button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Payment details --}}
        @php($detailFields = ['payment_method', 'momo_network', 'momo_number', 'bank_name', 'account_number', 'account_name'])
        <section id="payment-details" class="card mt-8 scroll-mt-24 p-5 sm:p-6" aria-labelledby="details-heading">
            <h2 id="details-heading" class="text-lg font-semibold text-ink">Payment details</h2>
            @if ($paymentDetails)
                <p class="mt-1.5 text-[0.9rem] text-body">Payouts are sent to <strong class="text-ink">{{ $paymentDetails }}</strong>.</p>
            @else
                <p class="mt-1.5 text-[0.9rem] text-body">Add your mobile money or bank details so we can pay you.</p>
            @endif
            @if ($pendingDetails)
                <p class="mt-3 rounded-md bg-sand px-4 py-3 text-[0.86rem] text-ink" role="status">Waiting for your confirmation: <strong>{{ $pendingDetails }}</strong>. Open the link we emailed you to start using these details.</p>
            @endif

            <details class="mt-4" @if ($errors->hasAny($detailFields)) open @endif>
                <summary class="inline-flex cursor-pointer items-center gap-1.5 text-[0.9rem] font-semibold text-brand-700">
                    <x-icon name="plus" class="size-4" /> {{ $paymentDetails ? 'Change payment details' : 'Add your payment details' }}
                </summary>
                <form method="POST" action="{{ route('partner.details.change', $token) }}" class="mt-4 space-y-4" novalidate
                      x-data="paymentMethod" data-method="{{ old('payment_method') }}">
                    @csrf
                    <fieldset>
                        <legend class="field-label">How should we pay you?</legend>
                        <div class="flex flex-wrap gap-x-6 gap-y-2">
                            @foreach (['momo' => 'Mobile money', 'bank' => 'Bank account'] as $value => $label)
                                <label class="inline-flex items-center gap-2 text-[0.92rem] text-ink">
                                    <input type="radio" name="payment_method" value="{{ $value }}" @checked(old('payment_method') === $value) @change="choose"
                                           class="size-4 border-line-strong text-brand-700 focus:ring-brand-600"> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @error('payment_method')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </fieldset>

                    <div class="grid gap-4 sm:grid-cols-2" x-show="isMomo()">
                        <div>
                            <label for="momo_network" class="field-label">Network</label>
                            <select id="momo_network" name="momo_network" class="field-input" @error('momo_network') aria-invalid="true" @enderror>
                                <option value="">Choose…</option>
                                @foreach ($networks as $value => $label)
                                    <option value="{{ $value }}" @selected(old('momo_network') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('momo_network')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <x-form.input name="momo_number" label="Mobile money number" type="tel" inputmode="tel" autocomplete="off" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2" x-show="isBank()">
                        <x-form.input name="bank_name" label="Bank" autocomplete="off" />
                        <x-form.input name="account_number" label="Account number" autocomplete="off" inputmode="numeric" />
                    </div>

                    <x-form.input name="account_name" label="Name on the account" autocomplete="name" />

                    <button type="submit" class="btn-primary w-full sm:w-auto">Save and email me a confirmation link</button>
                    <p class="field-help">For your security, new details are used only after you confirm them from the email we send to you.</p>
                </form>
            </details>
        </section>

        {{-- Orders --}}
        <section class="card mt-8" aria-labelledby="orders-heading">
            <h2 id="orders-heading" class="px-5 pt-5 text-lg font-semibold text-ink sm:px-6">Referred orders</h2>
            @if ($orders->isEmpty())
                <p class="px-5 pt-2 pb-6 text-[0.9rem] text-body sm:px-6">No paid orders yet.</p>
            @else
                {{-- Phones: one block per order --}}
                <ul class="mt-3 divide-y divide-line border-t border-line sm:hidden">
                    @foreach ($orders as $order)
                        <li class="px-5 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold text-ink">{{ $order['service'] }}</p>
                                    <p class="text-[0.82rem] text-muted">{{ $order['date'] }}</p>
                                </div>
                                <span class="inline-flex shrink-0 rounded-full px-2.5 py-0.5 text-[0.75rem] font-medium {{ $statusClasses[$order['status']] ?? 'bg-sand text-ink' }}">{{ $order['status'] }}</span>
                            </div>
                            <dl class="mt-2 grid grid-cols-2 gap-2 text-[0.85rem]">
                                <div><dt class="text-muted">Amount paid</dt><dd class="font-medium text-ink tabular-nums">{{ $order['amount'] }}</dd></div>
                                <div><dt class="text-muted">Your commission</dt><dd class="font-medium text-ink tabular-nums">{{ $order['commission'] }}</dd></div>
                            </dl>
                        </li>
                    @endforeach
                </ul>

                {{-- Wider screens: a table --}}
                <div class="mt-3 hidden sm:block">
                    <table class="w-full text-left text-[0.9rem]">
                        <thead class="border-y border-line bg-cream text-[0.78rem] text-muted">
                            <tr>
                                <th scope="col" class="px-6 py-2.5 font-medium">Date</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">Service</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Amount paid</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Your commission</th>
                                <th scope="col" class="px-6 py-2.5 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($orders as $order)
                                <tr>
                                    <td class="px-6 py-3 whitespace-nowrap text-body">{{ $order['date'] }}</td>
                                    <td class="px-3 py-3 text-ink">{{ $order['service'] }}</td>
                                    <td class="px-3 py-3 text-right whitespace-nowrap text-ink tabular-nums">{{ $order['amount'] }}</td>
                                    <td class="px-3 py-3 text-right whitespace-nowrap text-ink tabular-nums">{{ $order['commission'] }}</td>
                                    <td class="px-6 py-3"><span class="inline-flex rounded-full px-2.5 py-0.5 text-[0.75rem] font-medium whitespace-nowrap {{ $statusClasses[$order['status']] ?? 'bg-sand text-ink' }}">{{ $order['status'] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($orders->hasPages())
                    <nav class="flex items-center justify-between gap-3 border-t border-line px-3 py-3 sm:px-4" aria-label="Referred orders pages">
                        @if ($orders->onFirstPage())
                            <span class="btn-ghost opacity-40" aria-disabled="true">‹ Newer</span>
                        @else
                            <a href="{{ $orders->previousPageUrl() }}" class="btn-ghost" rel="prev">‹ Newer</a>
                        @endif
                        <span class="text-[0.84rem] text-muted">Page {{ $orders->currentPage() }} of {{ $orders->lastPage() }}</span>
                        @if ($orders->hasMorePages())
                            <a href="{{ $orders->nextPageUrl() }}" class="btn-ghost" rel="next">Older ›</a>
                        @else
                            <span class="btn-ghost opacity-40" aria-disabled="true">Older ›</span>
                        @endif
                    </nav>
                @endif
            @endif
        </section>

        <p class="mt-8 text-[0.86rem] text-muted">
            Lost this link? <a href="{{ route('partner.request') }}" class="link">Email it to me again</a>.
            Questions about your commission or payouts? Write to <a href="mailto:{{ $supportEmail }}" class="link">{{ $supportEmail }}</a>.
        </p>
    </div>
</x-layouts.site>
