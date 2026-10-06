<x-layouts.site :seo="$seo">
    <article>
        <header class="border-b border-line bg-[#f7f5f1]">
            <div class="container-site max-w-3xl py-12 sm:py-16">
                <nav aria-label="Breadcrumb" class="text-[0.8rem] text-muted">
                    <a href="{{ route('resources.index') }}" class="hover:text-brand-700">Resources</a>
                    @if ($article->category)
                        <span aria-hidden="true" class="mx-1.5">/</span>
                        <a href="{{ route('resources.category', $article->category->slug) }}" class="hover:text-brand-700">{{ $article->category->name }}</a>
                    @endif
                </nav>
                <h1 class="display-1 mt-5">{{ $article->title }}</h1>
                @if ($article->excerpt)
                    <p class="lead mt-5">{{ $article->excerpt }}</p>
                @endif
                <p class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-1 text-[0.82rem] text-muted">
                    <span>{{ $article->author_name ?: 'Statementra Editorial' }}</span>
                    @if ($article->published_at)
                        <time datetime="{{ $article->published_at->toDateString() }}">{{ $article->published_at->format('j F Y') }}</time>
                    @endif
                    @if ($article->reading_minutes)
                        <span>{{ $article->reading_minutes }} min read</span>
                    @endif
                </p>
            </div>
        </header>

        @if ($article->cover_image_path)
            <div class="container-site max-w-4xl pt-10">
                <x-picture :path="$article->cover_image_path" :alt="$article->cover_image_alt ?? ''" :eager="true" sizes="(min-width: 1024px) 896px, 100vw"
                           img-class="aspect-[21/9] w-full rounded-[10px] object-cover" class="block" />
            </div>
        @endif

        <div class="container-site grid max-w-5xl gap-12 py-12 lg:grid-cols-[1fr_17rem]">
            <div class="prose-st max-w-none">{!! $html !!}</div>

            <aside class="lg:sticky lg:top-24 lg:self-start">
                <div class="card bg-brand-50/60 p-6">
                    <p class="font-serif text-xl text-ink">Want a document written around your story?</p>
                    <p class="mt-2 text-[0.88rem] text-body">We research your programme, verify requirements and write a personalized document — delivered by email.</p>
                    <a href="{{ route('order.start') }}" class="btn-primary mt-5 w-full">Start your application</a>
                    <p class="mt-3 text-center text-xs text-muted">No account required</p>
                </div>
            </aside>
        </div>
    </article>

    @if ($related->isNotEmpty())
        <section class="border-t border-line py-14" aria-labelledby="related-heading">
            <div class="container-site">
                <h2 id="related-heading" class="text-[1.6rem]">Keep reading</h2>
                <div class="mt-7 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($related as $item)
                        <x-marketing.article-card :article="$item" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <x-marketing.cta-band />
</x-layouts.site>
