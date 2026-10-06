@props(['siteName' => 'Statementra', 'tagline' => '', 'services' => [], 'socials' => [], 'compact' => false])
<footer class="bg-brand-800 text-white/85">
    <div class="container-site py-14">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-[1.5fr_1fr_1fr_1fr_1.1fr]">
            <div>
                <a href="{{ route('home') }}" class="font-serif text-[1.75rem] leading-none tracking-[-0.02em] text-white">{{ $siteName }}</a>
                <p class="mt-3 text-sm text-white/75">{{ $tagline }}</p>
                @if ($socials)
                    <ul class="mt-6 flex items-center gap-4" aria-label="Social media">
                        @foreach ($socials as $network => $url)
                            <li>
                                <a href="{{ $url }}" class="text-white/80 transition hover:text-white" rel="noopener" target="_blank">
                                    <x-icon :name="$network" class="size-[1.1rem]" :label="ucfirst(str_replace('-social', '', $network))" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @unless ($compact)
                <nav aria-label="Services">
                    <h2 class="font-sans text-sm font-semibold text-white">Services</h2>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        @foreach ($services as $service)
                            <li><a href="{{ route('services.show', $service['slug']) }}" class="hover:text-white">{{ $service['name'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>

                <nav aria-label="Resources">
                    <h2 class="font-sans text-sm font-semibold text-white">Resources</h2>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <li><a href="{{ route('resources.index') }}" class="hover:text-white">Guides</a></li>
                        <li><a href="{{ route('pages.how-it-works') }}" class="hover:text-white">How It Works</a></li>
                        <li><a href="{{ route('resources.category', 'application-tips') }}" class="hover:text-white">Writing Resources</a></li>
                        <li><a href="{{ route('pages.faq') }}" class="hover:text-white">FAQ</a></li>
                    </ul>
                </nav>

                <nav aria-label="Company">
                    <h2 class="font-sans text-sm font-semibold text-white">Company</h2>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <li><a href="{{ route('pages.show', 'about') }}" class="hover:text-white">About</a></li>
                        <li><a href="{{ route('contact.show') }}" class="hover:text-white">Contact</a></li>
                        <li><a href="{{ route('pages.show', 'privacy-policy') }}" class="hover:text-white">Privacy</a></li>
                        <li><a href="{{ route('pages.show', 'terms') }}" class="hover:text-white">Terms</a></li>
                        <li><a href="{{ route('pages.show', 'refund-policy') }}" class="hover:text-white">Refund Policy</a></li>
                    </ul>
                </nav>
            @endunless

            <div class="flex flex-col justify-between gap-6 {{ $compact ? 'sm:col-span-1 lg:col-start-5' : '' }}">
                <p class="flex items-start gap-3 text-sm">
                    <x-icon name="lock" class="mt-0.5 size-5 shrink-0 text-white" />
                    <span>Secure payments<br>via Paystack</span>
                </p>
                <p class="text-xs text-white/60">&copy; {{ date('Y') }} {{ $siteName }}. All rights reserved.</p>
            </div>
        </div>
    </div>
</footer>
