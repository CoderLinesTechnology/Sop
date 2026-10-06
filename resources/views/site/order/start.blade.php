@php
    use App\Enums\FieldMapping;
    use App\Enums\FieldSection;

    $sections = $form->sections();
    $fileFields = $form->fileFields();
    $number = 1;

    $mappedKey = fn (FieldMapping $mapping) => $form->inputFields()->first(fn ($f) => $f->maps_to === $mapping)?->key;
    $summary = array_filter([
        'Name' => $mappedKey(FieldMapping::CustomerName),
        'Email' => $mappedKey(FieldMapping::Email),
        'University' => $mappedKey(FieldMapping::Institution),
        'Programme' => $mappedKey(FieldMapping::Programme),
        'Country' => $mappedKey(FieldMapping::Country),
        'Deadline' => $mappedKey(FieldMapping::Deadline),
    ]);
    $notesKey = $sections['additional']['fields'][0]->key ?? null;
    $acceptAll = collect($fileFields)->flatMap(fn ($f) => $f->acceptedExtensions())->unique()->values()->all() ?: \App\Support\Settings::allowedUploadExtensions();
    $acceptAttr = collect($acceptAll)->map(fn ($e) => '.'.$e)->push(in_array('jpg', $acceptAll, true) ? '.jpeg' : null)->filter()->implode(',');

    $config = [
        'values' => $values,
        'uploads' => $uploads,
        'uploadUrl' => route('order.uploads.store', $service->slug),
        'maxBytes' => \App\Support\Settings::maxUploadBytes(),
        'accept' => array_values(array_unique([...$acceptAll, ...(in_array('jpg', $acceptAll, true) ? ['jpeg'] : [])])),
        'slots' => $fileFields->mapWithKeys(fn ($f) => [$f->key => $f->label])->all() + ['other' => 'Other documents'],
        'conditions' => $form->inputFields()->filter(fn ($f) => ! empty($f->show_when['field'] ?? null))->mapWithKeys(fn ($f) => [$f->key => $f->show_when])->all(),
        'dateKeys' => $form->inputFields()->where('type', \App\Enums\FieldType::Date)->pluck('key')->values()->all(),
        'privateKeys' => [],
        'hasErrors' => $errors->any(),
    ];
    $editQuery = $editing ? '?order='.$editing->reference : '';
@endphp
<x-layouts.site :seo="$seo" :scripts="['resources/js/checkout.js']" main-class="bg-[#f7f5f1]">
    <div class="container-site py-8 sm:py-12">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="eyebrow">{{ $editing ? 'Edit your application' : 'New application' }}</p>
                <h1 class="display-1 mt-3">Let’s get started</h1>
                <p class="lead mt-3">Fill in the details below and tell us what you need. It only takes a few minutes.</p>
            </div>
            <x-checkout.stepper current="application" class="lg:mb-2" />
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md bg-tint-yellow px-4 py-3 text-sm text-tint-yellow-ink" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="mt-6 rounded-md border border-coral/40 bg-tint-rose px-4 py-3 text-sm text-tint-rose-ink" role="alert" tabindex="-1">
                <p class="font-semibold">Please check the highlighted fields.</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach (array_slice($errors->all(), 0, 6) as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('order.store', $service->slug) }}" enctype="multipart/form-data" novalidate
              x-data="applicationForm" data-config="{{ json_encode($config, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}"
              data-track-form-start="{{ $service->slug }}"
              @input="onInput" @change="onInput" @submit="onSubmit"
              class="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_21rem] lg:items-start">
            @csrf
            @if ($editing)
                <input type="hidden" name="order" value="{{ $editing->reference }}">
            @endif
            <input type="hidden" name="{{ \App\Support\SpamGuard::TIMESTAMP }}" value="{{ \App\Support\SpamGuard::token() }}">
            <div class="hidden" aria-hidden="true"><label>Website <input type="text" name="{{ \App\Support\SpamGuard::HONEYPOT }}" tabindex="-1" autocomplete="off"></label></div>

            <div class="card space-y-10 p-5 sm:p-8">
                {{-- 1. Service --}}
                <fieldset id="service-choice">
                    <legend class="font-serif text-[1.3rem] text-ink">{{ $number++ }}. What do you need help with?</legend>
                    <p class="mt-1 text-[0.86rem] text-muted">Choose the type of essay or document you need.</p>

                    <div class="mt-5 hidden gap-3 sm:grid sm:grid-cols-3 lg:grid-cols-{{ min(5, max(3, $services->count())) }}">
                        @foreach ($services as $option)
                            @php($selected = $option->id === $service->id)
                            <a href="{{ route('order.start', $option->slug) }}{{ $editQuery }}" @click="rememberBeforeSwitch"
                               @class(['group flex flex-col items-center gap-2 rounded-[10px] border px-3 pt-4 pb-3 text-center transition',
                                   'border-brand-700 bg-brand-50 ring-1 ring-brand-700' => $selected,
                                   'border-line bg-white hover:border-line-strong' => ! $selected])
                               @if ($selected) aria-current="true" @endif>
                                <x-icon :name="$option->icon" class="size-6 text-ink" stroke="1.5" />
                                <span class="text-[0.8rem] leading-tight font-medium text-ink">{{ $option->name }}</span>
                                <span @class(['mt-1 inline-flex size-5 items-center justify-center rounded-full border-2', 'border-brand-700' => $selected, 'border-line-strong' => ! $selected]) aria-hidden="true">
                                    @if ($selected)<span class="size-2.5 rounded-full bg-brand-700"></span>@endif
                                </span>
                                <span class="sr-only">{{ $selected ? '(selected)' : '' }}</span>
                            </a>
                        @endforeach
                    </div>

                    <div class="relative mt-4 sm:hidden">
                        <label for="service-select" class="sr-only">Document type</label>
                        <select id="service-select" class="field-input appearance-none pr-10" @change="changeService">
                            @foreach ($services as $option)
                                <option value="{{ route('order.start', $option->slug) }}{{ $editQuery }}" @selected($option->id === $service->id)>{{ $option->name }}</option>
                            @endforeach
                        </select>
                        <x-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted" />
                    </div>
                    @if ($service->order_instructions)
                        <p class="mt-4 rounded-md bg-sand px-4 py-3 text-[0.86rem] text-body">{{ $service->order_instructions }}</p>
                    @endif
                </fieldset>

                {{-- Details & application --}}
                @foreach (['details', 'application'] as $sectionKey)
                    @if (isset($sections[$sectionKey]))
                        <fieldset class="border-t border-line pt-8">
                            <legend class="font-serif text-[1.3rem] text-ink">{{ $number++ }}. {{ $sections[$sectionKey]['section']->getLabel() }}</legend>
                            <p class="mt-1 text-[0.86rem] text-muted">{{ $sections[$sectionKey]['section']->description() }}</p>
                            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                                @foreach ($sections[$sectionKey]['fields'] as $field)
                                    @include('site.order.partials.field', ['field' => $field])
                                @endforeach
                            </div>
                        </fieldset>
                    @endif
                @endforeach

                {{-- Uploads --}}
                @if ($fileFields->isNotEmpty())
                    <fieldset class="border-t border-line pt-8">
                        <legend class="font-serif text-[1.3rem] text-ink">{{ $number++ }}. Upload what you have <span class="font-sans text-[0.8rem] text-muted">(optional)</span></legend>
                        <p class="mt-1 text-[0.86rem] text-muted">Add any relevant documents. You don’t need to upload everything — a CV saves you the most typing.</p>

                        <div class="mt-5 rounded-[10px] border-2 border-dashed px-4 py-8 text-center transition"
                             :class="dragging ? 'border-brand-600 bg-brand-50' : 'border-line-strong bg-cream/60'"
                             @dragover.prevent="onDragOver" @dragleave.prevent="onDragLeave" @drop.prevent="onDrop">
                            <x-icon name="upload-cloud" class="mx-auto size-8 text-ink" stroke="1.4" />
                            <label for="upload-any" class="mt-3 block cursor-pointer text-[0.9rem] font-semibold text-ink">
                                <span class="hidden sm:inline">Drag and drop files here</span><span class="sm:hidden">Tap to upload</span>
                                <span class="mt-0.5 block text-[0.84rem] font-normal text-body"><span class="hidden sm:inline">or </span><span class="text-brand-700 underline underline-offset-4">click to browse</span></span>
                            </label>
                            <input id="upload-any" type="file" class="sr-only" multiple accept="{{ $acceptAttr }}" data-slot="other" @change="onPick">
                            <p class="mt-2 text-[0.75rem] text-muted">{{ collect($acceptAll)->map(fn ($e) => strtoupper($e))->implode(', ') }} (Max {{ $maxUploadMb }}MB)</p>
                        </div>

                        <div class="mt-3 grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($fileFields as $slot)
                                <label for="upload-{{ $slot->key }}" class="flex cursor-pointer items-center gap-3 rounded-md border bg-white px-3.5 py-3 text-[0.84rem] font-medium text-ink transition hover:border-brand-600"
                                       :class="hasUpload('{{ $slot->key }}') ? 'border-brand-600 bg-brand-50' : 'border-line'">
                                    <x-icon name="document" class="size-5 shrink-0" stroke="1.5" />
                                    <span class="flex-1">{{ $slot->label }}</span>
                                    <span class="inline-flex items-center gap-1 text-[0.7rem] font-semibold text-brand-700" x-show="hasUpload('{{ $slot->key }}')" x-cloak>
                                        <x-icon name="check" class="size-3.5" stroke="2.5" /><span x-text="uploadCount('{{ $slot->key }}')"></span>
                                    </span>
                                </label>
                                <input id="upload-{{ $slot->key }}" type="file" class="sr-only" data-slot="{{ $slot->key }}" @change="onPick"
                                       accept="{{ collect($slot->acceptedExtensions())->map(fn ($e) => '.'.$e)->implode(',') }}{{ in_array('jpg', $slot->acceptedExtensions(), true) ? ',.jpeg' : '' }}"
                                       @if ($slot->maxFiles() > 1) multiple @endif aria-describedby="upload-{{ $slot->key }}-help">
                                <span id="upload-{{ $slot->key }}-help" class="sr-only">{{ $slot->help_text }}</span>
                            @endforeach
                        </div>

                        <ul class="mt-4 space-y-2" aria-live="polite">
                            <template x-for="file in uploads" :key="file.id">
                                <li class="rounded-md border border-line bg-white px-3.5 py-3">
                                    <div class="flex items-center gap-3">
                                        <x-icon name="file" class="size-5 shrink-0 text-muted" stroke="1.5" />
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-[0.86rem] font-medium text-ink" x-text="file.name"></p>
                                            <p class="text-[0.75rem] text-muted"><span x-text="slotLabel(file.slot)"></span> · <span x-text="file.size"></span></p>
                                        </div>
                                        <span class="text-[0.75rem] text-muted" x-show="file.status === 'uploading'"><span x-text="file.progress"></span>%</span>
                                        <x-icon name="check-circle" class="size-5 text-brand-600" x-show="file.status === 'done'" />
                                        <button type="button" class="inline-flex size-9 items-center justify-center rounded-md text-muted hover:bg-sand hover:text-ink" @click="remove(file.id)">
                                            <x-icon name="x" class="size-4" /><span class="sr-only">Remove file</span>
                                        </button>
                                    </div>
                                    <div class="mt-2 h-1 overflow-hidden rounded-full bg-sand" x-show="file.status === 'uploading'">
                                        <div class="h-full rounded-full bg-brand-600 transition-all" :style="progressStyle(file)"></div>
                                    </div>
                                    <p class="mt-1.5 text-[0.8rem] text-tint-rose-ink" x-show="file.status === 'error'" x-text="file.error" role="alert"></p>
                                    <template x-if="file.status === 'done'">
                                        <input type="hidden" :name="inputName(file)" :value="file.uuid">
                                    </template>
                                </li>
                            </template>
                        </ul>

                        <noscript>
                            <label class="field-label mt-4" for="files-fallback">Choose files</label>
                            <input id="files-fallback" type="file" name="files[other][]" multiple accept="{{ $acceptAttr }}" class="field-input">
                        </noscript>
                        @error('files.*')
                            <p class="field-error" role="alert">{{ $message }}</p>
                        @enderror
                    </fieldset>
                @endif

                {{-- Story & additional --}}
                @foreach (['story', 'additional'] as $sectionKey)
                    @if (isset($sections[$sectionKey]))
                        <fieldset class="border-t border-line pt-8">
                            <legend class="font-serif text-[1.3rem] text-ink">
                                {{ $number++ }}. {{ $sections[$sectionKey]['section']->getLabel() }}
                                @if ($sections[$sectionKey]['fields']->every(fn ($f) => $f->requirement !== \App\Enums\RequirementLevel::Required))
                                    <span class="font-sans text-[0.8rem] text-muted">(optional)</span>
                                @endif
                            </legend>
                            <p class="mt-1 text-[0.86rem] text-muted">{{ $sections[$sectionKey]['section']->description() }}</p>
                            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                                @foreach ($sections[$sectionKey]['fields'] as $field)
                                    @include('site.order.partials.field', ['field' => $field])
                                @endforeach
                            </div>
                        </fieldset>
                    @endif
                @endforeach

                <div class="border-t border-line pt-6">
                    <p class="mb-3 text-sm font-medium text-tint-rose-ink" x-show="formError" x-text="formError" role="alert" x-cloak></p>
                    <button type="submit" class="btn-primary w-full" :disabled="submitting">
                        <span x-show="!submitting">Continue to Review</span>
                        <span x-show="submitting" x-cloak>Saving…</span>
                        <x-icon name="arrow-right" class="size-4" />
                    </button>
                    <p class="mt-3 flex items-center justify-center gap-2 text-xs text-muted"><x-icon name="lock" class="size-3.5" /> Your information is encrypted and kept confidential.</p>
                </div>
            </div>

            {{-- Live summary --}}
            <aside class="lg:sticky lg:top-24" aria-label="Your application summary">
                <div class="card p-5 sm:p-6">
                    <x-ui.icon-badge icon="document" />
                    <h2 class="mt-3 text-[1.45rem]">Your Application</h2>

                    <div class="mt-5 flex items-start justify-between gap-3 border-b border-line pb-5">
                        <div>
                            <p class="text-[0.78rem] text-muted">Service</p>
                            <p class="mt-0.5 text-[0.92rem] font-medium text-ink">{{ $service->name }}</p>
                        </div>
                        <a href="#service-choice" class="inline-flex items-center gap-1 text-[0.78rem] font-semibold text-brand-700"><x-icon name="pencil" class="size-3.5" /> Change</a>
                    </div>

                    @if ($summary)
                        <div class="border-b border-line py-5">
                            <h3 class="font-serif text-[1.02rem]">Application Details</h3>
                            <dl class="mt-3 space-y-2 text-[0.84rem]">
                                @foreach ($summary as $label => $key)
                                    <div class="grid grid-cols-[6rem_1fr] gap-2">
                                        <dt class="text-muted">{{ $label }}</dt>
                                        <dd class="min-w-0 break-words text-ink" x-text="display('{{ $key }}', '—')">—</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endif

                    @if ($fileFields->isNotEmpty())
                        <div class="border-b border-line py-5">
                            <h3 class="font-serif text-[1.02rem]">Files <span class="font-sans text-[0.75rem] text-muted">(optional)</span></h3>
                            <div class="mt-3 flex items-start gap-3 text-[0.82rem]" x-show="!completedUploads().length">
                                <x-icon name="document" class="size-5 shrink-0 text-muted" stroke="1.5" />
                                <p class="text-ink">No files uploaded yet<span class="block text-[0.75rem] text-muted">You can add them now or later.</span></p>
                            </div>
                            <ul class="mt-3 space-y-1.5 text-[0.82rem]" x-show="completedUploads().length" x-cloak>
                                <template x-for="file in completedUploads()" :key="file.id">
                                    <li class="flex items-center gap-2 text-ink"><x-icon name="check" class="size-3.5 text-brand-600" stroke="2.5" /><span class="truncate" x-text="file.name"></span></li>
                                </template>
                            </ul>
                        </div>
                    @endif

                    @if ($notesKey)
                        <div class="border-b border-line py-5">
                            <h3 class="font-serif text-[1.02rem]">Extra Information</h3>
                            <p class="mt-3 flex items-start gap-3 text-[0.82rem] text-ink">
                                <x-icon name="chat" class="size-5 shrink-0 text-muted" stroke="1.5" />
                                <span x-text="hasValue('{{ $notesKey }}') ? excerpt('{{ $notesKey }}', 140) : 'No additional notes'">No additional notes</span>
                            </p>
                        </div>
                    @endif

                    <div class="pt-5">
                        <div class="flex items-end justify-between gap-3">
                            <p class="text-[0.82rem] font-medium text-ink">Estimated total</p>
                            <x-ui.price :quote="$quote" size="md" class="justify-end" />
                        </div>
                        <p class="mt-3 flex items-center gap-2 text-[0.8rem] text-muted"><x-icon name="clock" class="size-4" /> Delivery: {{ $service->deliveryLabel() }}</p>
                    </div>
                </div>
                <p class="mt-4 flex items-start gap-3 rounded-[10px] bg-tint-green/70 px-4 py-3 text-[0.8rem] text-tint-green-ink">
                    <x-icon name="shield-check" class="mt-0.5 size-5 shrink-0" /> Your information is secure. We use industry-standard encryption to keep your data safe and private.
                </p>
            </aside>
        </form>
    </div>
</x-layouts.site>
