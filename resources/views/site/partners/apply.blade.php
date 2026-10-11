@php
    $hero = $page?->section('hero') ?? [];
    $details = $page?->section('details') ?? [];
    $oldChannels = (array) old('channels', []);
    $oldLinks = array_values((array) old('links', []));
@endphp
<x-layouts.site :seo="$seo">
    <section class="border-b border-line bg-[#f7f5f1]">
        <div class="container-site max-w-3xl py-14 sm:py-16">
            <p class="eyebrow">{{ $hero['eyebrow'] ?? 'Partner programme' }}</p>
            <h1 class="display-1 mt-4">{!! nl2br(e($hero['title'] ?? 'Become a partner')) !!}</h1>
            @if (! empty($hero['text']))
                <p class="lead mt-5">{{ $hero['text'] }}</p>
            @endif
        </div>
    </section>

    <section class="py-14 sm:py-16">
        <div class="container-site grid max-w-5xl gap-12 lg:grid-cols-[1fr_1.5fr]">
            <aside>
                <h2 class="text-[1.3rem]">How it works</h2>
                <ol class="mt-5 space-y-6">
                    @foreach ($details as $detail)
                        <li class="flex gap-4">
                            <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-tint-green font-semibold text-brand-700">{{ $loop->iteration }}</span>
                            <div>
                                <h3 class="font-sans text-[1rem] font-semibold text-ink">{{ $detail['title'] ?? '' }}</h3>
                                <p class="mt-1 text-[0.9rem] leading-relaxed text-body">{{ $detail['text'] ?? '' }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
                <p class="mt-8 text-[0.86rem] text-muted">Already a partner? <a href="{{ route('partner.request') }}" class="link">Get your dashboard link</a></p>
            </aside>

            <div id="apply" class="card scroll-mt-24 p-6 sm:p-8">
                @if (session('status'))
                    <div class="mb-6 flex items-start gap-3 rounded-md bg-mint px-4 py-3 text-sm text-mint-ink" role="status">
                        <x-icon name="check-circle" class="mt-0.5 size-5 shrink-0" /> {{ session('status') }}
                    </div>
                @endif

                @if (! $open)
                    <h2 class="text-[1.3rem]">Applications are closed right now</h2>
                    <p class="mt-3 text-[0.95rem] text-body">We’re not taking new partner applications at the moment. Please check back later, or <a href="{{ route('contact.show') }}" class="link">contact us</a>.</p>
                @else
                    <h2 class="text-[1.3rem]">Apply</h2>
                    <form method="POST" action="{{ route('partners.apply.store') }}" class="mt-6 space-y-5" novalidate>
                        @csrf
                        <input type="hidden" name="{{ \App\Support\SpamGuard::TIMESTAMP }}" value="{{ \App\Support\SpamGuard::token() }}">
                        <div class="hidden" aria-hidden="true"><label>Website <input type="text" name="{{ \App\Support\SpamGuard::HONEYPOT }}" tabindex="-1" autocomplete="off"></label></div>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-form.input name="full_name" label="Full name" autocomplete="name" required maxlength="120" />
                            <x-form.input name="email" type="email" label="Email address" autocomplete="email" required maxlength="190" />
                            <x-form.input name="phone" type="tel" label="Phone number" autocomplete="tel" required maxlength="40" />
                            <x-form.input name="city" label="City" autocomplete="address-level2" required maxlength="80" />
                        </div>

                        <div>
                            <label for="f-country" class="field-label">Country<span class="text-coral" aria-hidden="true"> *</span></label>
                            <select id="f-country" name="country" required class="field-input" @error('country') aria-invalid="true" @enderror>
                                <option value="">Choose…</option>
                                @foreach ($countries as $code => $countryName)
                                    <option value="{{ $code }}" @selected(old('country', 'GH') === $code)>{{ $countryName }}</option>
                                @endforeach
                            </select>
                            @error('country')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <fieldset>
                            <legend class="field-label">How will you share Statementra?<span class="text-coral" aria-hidden="true"> *</span></legend>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach ($channels as $value => $label)
                                    <label class="flex items-center gap-2.5 text-[0.92rem] text-ink">
                                        <input type="checkbox" name="channels[]" value="{{ $value }}" @checked(in_array($value, $oldChannels, true))
                                               class="size-4 rounded border-line-strong text-brand-700 focus:ring-brand-600"> {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            @error('channels')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </fieldset>
                        <x-form.textarea name="channels_details" label="Tell us a little more" optional rows="2" maxlength="500"
                                         help="For example the groups, pages or campuses you have in mind. Required if you chose “Other”." />

                        <div>
                            <label for="f-audience" class="field-label">Roughly how many people could you reach?<span class="text-coral" aria-hidden="true"> *</span></label>
                            <select id="f-audience" name="audience_size" required class="field-input" @error('audience_size') aria-invalid="true" @enderror>
                                <option value="">Choose…</option>
                                @foreach ($audiences as $value => $label)
                                    <option value="{{ $value }}" @selected(old('audience_size') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('audience_size')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <fieldset>
                            <legend class="field-label">Social or profile links <span class="tag-optional">(optional, up to three)</span></legend>
                            <div class="space-y-2">
                                @foreach (range(0, 2) as $i)
                                    <div>
                                        <label for="f-link-{{ $i }}" class="sr-only">Link {{ $i + 1 }}</label>
                                        <input id="f-link-{{ $i }}" name="links[{{ $i }}]" type="url" inputmode="url" maxlength="255" placeholder="https://"
                                               value="{{ $oldLinks[$i] ?? '' }}" class="field-input" @error('links.'.$i) aria-invalid="true" @enderror>
                                        @error('links.'.$i)<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>

                        <x-form.textarea name="motivation" label="Why would you like to partner with us?" required rows="4" maxlength="2000" />
                        <x-form.input name="preferred_code" label="Preferred referral code" optional maxlength="40" class="uppercase"
                                      help="Letters, numbers and dashes. We’ll use it if it’s free." />

                        <div>
                            <label class="flex items-start gap-3 text-[0.9rem] text-ink">
                                <input type="checkbox" name="consent" value="1" @checked(old('consent')) required
                                       class="mt-0.5 size-5 rounded border-line-strong text-brand-700 focus:ring-brand-600" @error('consent') aria-invalid="true" @enderror>
                                <span>I have read the <a href="{{ route('pages.show', 'partner-terms') }}" class="link">partner terms</a> and the <a href="{{ route('pages.show', 'privacy-policy') }}" class="link">privacy notice</a>, and agree to be contacted about my application.</span>
                            </label>
                            @error('consent')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <button type="submit" class="btn-primary w-full sm:w-auto">Send application <x-icon name="send" class="size-4" /></button>
                        <p class="field-help">We’ll email you a link to confirm your address. Your application is reviewed once you confirm.</p>
                    </form>
                @endif
            </div>
        </div>
    </section>
</x-layouts.site>
