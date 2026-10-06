@props(['rating' => 5])
<span {{ $attributes->class(['inline-flex items-center gap-0.5 text-[#E2A33B]']) }} role="img" aria-label="Rated {{ $rating }} out of 5">
    @for ($i = 1; $i <= 5; $i++)
        <x-icon :name="$i <= $rating ? 'star-solid' : 'star'" class="size-4" />
    @endfor
</span>
