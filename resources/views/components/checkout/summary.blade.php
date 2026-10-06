@props(['order', 'countryName' => null, 'editable' => false])
@php($editUrl = route('order.start', $order->service->slug).'?order='.$order->reference)
<div {{ $attributes->class(['card p-5 sm:p-6']) }}>
    <div class="flex items-center gap-3">
        <x-ui.icon-badge icon="document" />
        <h2 class="text-[1.35rem]">Application Summary</h2>
    </div>

    <dl class="mt-5 divide-y divide-line text-[0.86rem]">
        <div class="flex items-start justify-between gap-3 pb-4">
            <div class="flex gap-3">
                <x-icon name="document" class="mt-0.5 size-5 shrink-0 text-muted" stroke="1.5" />
                <div><dt class="font-semibold text-ink">Service</dt><dd class="mt-1 text-body">{{ $order->serviceName() }}</dd></div>
            </div>
            @if ($editable)<a href="{{ route('order.start', $order->service->slug) }}?order={{ $order->reference }}#service-choice" class="inline-flex items-center gap-1 text-[0.78rem] font-semibold text-brand-700"><x-icon name="pencil" class="size-3.5" /> Change</a>@endif
        </div>
        <div class="flex gap-3 py-4">
            <x-icon name="user" class="mt-0.5 size-5 shrink-0 text-muted" stroke="1.5" />
            <div class="min-w-0"><dt class="font-semibold text-ink">Applicant</dt>
                <dd class="mt-1 space-y-0.5 break-words text-body">
                    @if ($order->customer_name)<span class="block">{{ $order->customer_name }}</span>@endif
                    @if ($order->email)<span class="block">{{ $order->email }}</span>@endif
                    @if ($order->customer_phone)<span class="block">{{ $order->customer_phone }}</span>@endif
                </dd>
            </div>
        </div>
        <div class="flex gap-3 py-4">
            <x-icon name="graduation-cap" class="mt-0.5 size-5 shrink-0 text-muted" stroke="1.5" />
            <div><dt class="font-semibold text-ink">Application Details</dt>
                <dd class="mt-1 space-y-0.5 text-body">
                    @foreach (array_filter([$order->institution, $order->programme, $countryName, $order->deadline ? 'Deadline: '.$order->deadline->format('j F Y') : null]) as $line)
                        <span class="block">{{ $line }}</span>
                    @endforeach
                </dd>
            </div>
        </div>
        <div class="flex items-start justify-between gap-3 py-4">
            <div class="flex gap-3">
                <x-icon name="file" class="mt-0.5 size-5 shrink-0 text-muted" stroke="1.5" />
                <div><dt class="font-semibold text-ink">Documents Uploaded</dt>
                    <dd class="mt-1 text-body">
                        @forelse ($order->files as $file)
                            <span class="block">• {{ $file->purposeLabel() }}</span>
                        @empty
                            <span class="text-muted">No files uploaded</span>
                        @endforelse
                    </dd>
                </div>
            </div>
            @if ($order->files->isNotEmpty())<span class="rounded-full bg-mint px-2 py-0.5 text-[0.7rem] font-semibold text-mint-ink">{{ $order->files->count() }} {{ \Illuminate\Support\Str::plural('file', $order->files->count()) }}</span>@endif
        </div>
    </dl>
    <p class="mt-2 flex items-start gap-3 rounded-[10px] bg-tint-green/70 px-4 py-3 text-[0.8rem] text-tint-green-ink">
        <x-icon name="shield-check" class="mt-0.5 size-5 shrink-0" /> <span><strong class="font-semibold">Your information is secure.</strong> We use industry-standard security to keep your data safe and private.</span>
    </p>
</div>
