@props(['current' => 'application', 'withDone' => false])
@php
    $steps = ['application' => 'Application', 'review' => 'Review', 'payment' => 'Payment'];
    if ($withDone) {
        $steps['done'] = 'Done';
    }
    $keys = array_keys($steps);
    $currentIndex = array_search($current, $keys, true);
@endphp
<nav aria-label="Checkout progress" {{ $attributes }}>
    <ol class="flex items-center gap-2 sm:gap-3">
        @foreach ($steps as $key => $label)
            @php($index = $loop->index)
            <li class="flex items-center gap-2 sm:gap-3">
                <span class="flex items-center gap-2" @if ($index === $currentIndex) aria-current="step" @endif>
                    @if ($index < $currentIndex)
                        <span class="inline-flex size-7 items-center justify-center rounded-full border border-brand-700 bg-white text-brand-700"><x-icon name="check" class="size-3.5" stroke="2.5" /></span>
                        <span class="text-[0.78rem] text-muted"><span class="sr-only">Completed: </span>{{ $label }}</span>
                    @elseif ($index === $currentIndex)
                        <span class="inline-flex size-7 items-center justify-center rounded-full bg-brand-700 text-[0.75rem] font-semibold text-white">{{ $index + 1 }}</span>
                        <span class="text-[0.78rem] font-semibold text-ink">{{ $label }}</span>
                    @else
                        <span class="inline-flex size-7 items-center justify-center rounded-full border border-line-strong bg-white text-[0.75rem] text-muted">{{ $index + 1 }}</span>
                        <span class="hidden text-[0.78rem] text-muted sm:inline">{{ $label }}</span>
                    @endif
                </span>
                @unless ($loop->last)
                    <span @class(['h-px w-6 sm:w-12', 'bg-brand-700' => $index < $currentIndex, 'bg-line-strong' => $index >= $currentIndex]) aria-hidden="true"></span>
                @endunless
            </li>
        @endforeach
    </ol>
</nav>
