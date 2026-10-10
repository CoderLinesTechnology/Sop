{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="en">
    <title>{{ $siteName }} guides</title>
@if ($subtitle)
    <subtitle>{{ $subtitle }}</subtitle>
@endif
    <link rel="self" type="application/atom+xml" href="{{ route('resources.feed') }}"/>
    <link rel="alternate" type="text/html" href="{{ route('resources.index') }}"/>
    <id>{{ route('resources.index') }}</id>
    <updated>{{ $updated }}</updated>
    <icon>{{ asset('icon-512.png') }}</icon>
    <author><name>{{ $siteName }}</name><uri>{{ url('/') }}</uri></author>
@foreach ($articles as $article)
    <entry>
        <title>{{ $article->title }}</title>
        <link rel="alternate" type="text/html" href="{{ route('resources.show', $article->slug) }}"/>
        <id>{{ route('resources.show', $article->slug) }}</id>
        <published>{{ ($article->published_at ?? $article->created_at)->toAtomString() }}</published>
        <updated>{{ ($article->updated_at ?? $article->published_at ?? $article->created_at)->toAtomString() }}</updated>
        <author><name>{{ $article->author_name ?: $siteName }}</name></author>
@if ($article->category)
        <category term="{{ $article->category->name }}"/>
@endif
@if ($article->excerpt)
        <summary>{{ $article->excerpt }}</summary>
@endif
    </entry>
@endforeach
</feed>
