<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} | {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-dvh flex-col bg-[#f7f5f1]">
    <header class="border-b border-line bg-white">
        <div class="container-site flex h-[4.25rem] items-center"><a href="{{ url('/') }}" class="font-serif text-[1.8rem] text-brand-700">{{ config('app.name') }}</a></div>
    </header>
    <main class="container-site flex max-w-xl flex-1 flex-col items-center justify-center py-20 text-center">
        <p class="eyebrow">Error {{ $code }}</p>
        <h1 class="display-2 mt-4">{{ $title }}</h1>
        <p class="lead mt-4">{{ $message }}</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ url('/') }}" class="btn-primary">Back to home</a>
            <a href="{{ url('/contact') }}" class="btn-outline">Contact support</a>
        </div>
    </main>
</body>
</html>
