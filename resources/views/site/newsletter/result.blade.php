<x-layouts.site :seo="\App\Support\Seo::make($title, index: false)" main-class="bg-[#f7f5f1]">
    <div class="container-site max-w-lg py-20 text-center">
        <x-ui.icon-badge icon="mail" size="lg" class="mx-auto" />
        <h1 class="display-2 mt-6">{{ $title }}</h1>
        <p class="lead mt-4">{{ $message }}</p>
        <a href="{{ route('resources.index') }}" class="btn-outline mt-8">Browse our guides</a>
    </div>
</x-layouts.site>
