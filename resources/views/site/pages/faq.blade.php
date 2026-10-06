@php($hero = $page?->section('hero') ?? [])
<x-layouts.site :seo="$seo">
    <section class="border-b border-line bg-[#f7f5f1]">
        <div class="container-site max-w-3xl py-14 text-center sm:py-16">
            <p class="eyebrow">{{ $hero['eyebrow'] ?? 'FAQ' }}</p>
            <h1 class="display-1 mt-4">{{ $hero['title'] ?? 'Frequently asked questions' }}</h1>
            <p class="lead mx-auto mt-5 max-w-xl">{{ $hero['text'] ?? '' }}</p>
        </div>
    </section>
    <section class="py-14 sm:py-16">
        <div class="container-site max-w-3xl">
            <x-ui.faq-list :faqs="$faqs" />
            <div class="card mt-10 flex flex-col items-start gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="font-serif text-xl text-ink">Still have a question?</p>
                    <p class="mt-1 text-[0.9rem] text-muted">Our support team is happy to help.</p>
                </div>
                <a href="{{ route('contact.show') }}" class="btn-outline">Contact support</a>
            </div>
        </div>
    </section>
</x-layouts.site>
