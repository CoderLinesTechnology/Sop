@php
    /** @var \App\Models\ServiceField $field */
    $key = $field->key;
    $name = 'answers['.$key.']';
    $id = 'answer-'.$key;
    $errorKey = 'answers.'.$key;
    $value = old($errorKey, $values[$key] ?? null);
    $required = $field->requirement === \App\Enums\RequirementLevel::Required;
    $adaptive = $required && $field->optional_when_upload;
    $hasError = $errors->has($errorKey);
    $describedBy = trim(($field->help_text ? $id.'-help ' : '').($hasError ? $id.'-error' : ''));
    $type = $field->type;
    $max = $field->maxLength();
    $common = 'id="'.$id.'" name="'.$name.'"'.($describedBy ? ' aria-describedby="'.$describedBy.'"' : '').($hasError ? ' aria-invalid="true"' : '');
    $requiredAttrs = ($required ? ' data-required="true"' : '').($adaptive ? ' data-optional-when-upload="'.e($field->optional_when_upload).'"' : '');
    $isHalf = $field->width === 'half';
@endphp
<div data-field="{{ $key }}" @class(['sm:col-span-1' => $isHalf, 'sm:col-span-2' => ! $isHalf]) @if ($field->show_when) x-show="isVisible('{{ $key }}')" @endif>
    @if ($type === \App\Enums\FieldType::Checkbox)
        <label class="flex items-start gap-3 text-[0.92rem] text-ink">
            <input type="hidden" name="{{ $name }}" value="0">
            <input type="checkbox" {!! $common !!}{!! $requiredAttrs !!} value="1" @checked((bool) $value) class="mt-0.5 size-5 rounded border-line-strong text-brand-700 focus:ring-brand-600">
            <span>{{ $field->label }}@if ($required)<span class="text-coral" aria-hidden="true"> *</span>@endif</span>
        </label>
    @else
        <label for="{{ $id }}" id="{{ $id }}-label" class="field-label">
            {{ $field->label }}
            @if ($adaptive)
                <span class="text-coral" aria-hidden="true" x-show="!hasUpload('{{ $field->optional_when_upload }}')"> *</span>
                <span class="tag-optional" x-show="hasUpload('{{ $field->optional_when_upload }}')" x-cloak>(optional — we'll use your CV)</span>
            @elseif ($required)
                <span class="text-coral" aria-hidden="true"> *</span>
            @elseif ($field->requirement === \App\Enums\RequirementLevel::Recommended)
                <span class="tag-optional">(recommended)</span>
            @else
                <span class="tag-optional">(optional)</span>
            @endif
        </label>

        @switch($type)
            @case(\App\Enums\FieldType::Textarea)
                <div class="relative">
                    <textarea {!! $common !!}{!! $requiredAttrs !!} rows="{{ $max <= 600 ? 3 : 4 }}" maxlength="{{ $max }}" placeholder="{{ $field->placeholder }}"
                              class="field-input resize-y pb-7 leading-relaxed">{{ is_array($value) ? '' : $value }}</textarea>
                    @if ($max <= 1000)
                        <span class="pointer-events-none absolute right-3 bottom-2 text-[0.7rem] text-muted tabular-nums" aria-hidden="true"><span x-text="charCount('{{ $key }}')">{{ mb_strlen((string) (is_array($value) ? '' : $value)) }}</span>/{{ $max }}</span>
                    @endif
                </div>
                @break

            @case(\App\Enums\FieldType::Select)
                <div class="relative">
                    <select {!! $common !!}{!! $requiredAttrs !!} class="field-input appearance-none pr-10">
                        <option value="">Select…</option>
                        @foreach ($field->choices() as $optionValue => $optionLabel)
                            <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                        @endforeach
                    </select>
                    <x-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted" />
                </div>
                @break

            @case(\App\Enums\FieldType::Country)
                <div class="relative">
                    <select {!! $common !!}{!! $requiredAttrs !!} class="field-input appearance-none pr-10" autocomplete="country">
                        <option value="">Select country</option>
                        <optgroup label="Popular destinations">
                            @foreach (\App\Support\Countries::POPULAR as $code)
                                <option value="{{ $code }}" @selected($value === $code)>{{ $countries[$code] ?? $code }}</option>
                            @endforeach
                        </optgroup>
                        <optgroup label="All countries">
                            @foreach ($countries as $code => $countryName)
                                <option value="{{ $code }}" @selected($value === $code && ! in_array($code, \App\Support\Countries::POPULAR, true))>{{ $countryName }}</option>
                            @endforeach
                        </optgroup>
                    </select>
                    <x-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted" />
                </div>
                @break

            @case(\App\Enums\FieldType::Radio)
                <div class="mt-1 flex flex-wrap gap-2" role="radiogroup" aria-labelledby="{{ $id }}-label">
                    @foreach ($field->choices() as $optionValue => $optionLabel)
                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-md border border-line-strong bg-white px-3.5 py-2 text-[0.9rem] text-ink has-[:checked]:border-brand-700 has-[:checked]:bg-brand-50">
                            <input type="radio" name="{{ $name }}" value="{{ $optionValue }}" @checked((string) $value === (string) $optionValue) class="size-4 border-line-strong text-brand-700 focus:ring-brand-600"{!! $requiredAttrs !!}>
                            {{ $optionLabel }}
                        </label>
                    @endforeach
                </div>
                @break

            @case(\App\Enums\FieldType::MultiSelect)
                <div class="mt-1 grid gap-2 sm:grid-cols-2">
                    @foreach ($field->choices() as $optionValue => $optionLabel)
                        <label class="inline-flex cursor-pointer items-center gap-2.5 rounded-md border border-line-strong bg-white px-3.5 py-2.5 text-[0.9rem] text-ink has-[:checked]:border-brand-700 has-[:checked]:bg-brand-50">
                            <input type="checkbox" name="{{ $name }}[]" value="{{ $optionValue }}" @checked(in_array($optionValue, (array) $value, true)) class="size-4 rounded border-line-strong text-brand-700 focus:ring-brand-600">
                            {{ $optionLabel }}
                        </label>
                    @endforeach
                </div>
                @break

            @case(\App\Enums\FieldType::Phone)
                <div class="flex gap-2">
                    <label for="{{ $id }}-dial" class="sr-only">Country dialling code</label>
                    <div class="relative w-[7.5rem] shrink-0">
                        <select id="{{ $id }}-dial" name="dial_code[{{ $key }}]" class="field-input appearance-none pr-8 text-[0.9rem]" autocomplete="tel-country-code">
                            @foreach ($dialCodes as $code => [$countryName, $dial])
                                <option value="{{ $code }}" @selected(old('dial_code.'.$key, $defaultDialCountry) === $code)>{{ $code }} {{ $dial }}</option>
                            @endforeach
                        </select>
                        <x-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-muted" />
                    </div>
                    <input type="tel" {!! $common !!}{!! $requiredAttrs !!} value="{{ $value }}" placeholder="{{ $field->placeholder ?: 'e.g. 24 123 4567' }}" autocomplete="tel-national" inputmode="tel" class="field-input">
                </div>
                @break

            @case(\App\Enums\FieldType::Date)
                <input type="date" {!! $common !!}{!! $requiredAttrs !!} value="{{ $value }}" min="{{ now()->subYear()->toDateString() }}" class="field-input">
                @break

            @case(\App\Enums\FieldType::Number)
                <input type="number" {!! $common !!}{!! $requiredAttrs !!} value="{{ $value }}" placeholder="{{ $field->placeholder }}" inputmode="numeric"
                       min="{{ (int) data_get($field->validation, 'min', 0) }}" max="{{ (int) data_get($field->validation, 'max', 1000000) }}" class="field-input">
                @break

            @default
                <input type="{{ ['email' => 'email', 'url' => 'url'][$type->value] ?? 'text' }}" {!! $common !!}{!! $requiredAttrs !!} value="{{ is_array($value) ? '' : $value }}"
                       placeholder="{{ $field->placeholder }}" maxlength="{{ min(1000, $max) }}"
                       @if ($type === \App\Enums\FieldType::Email) autocomplete="email" inputmode="email" @elseif ($field->maps_to === \App\Enums\FieldMapping::CustomerName) autocomplete="name" @endif
                       class="field-input">
        @endswitch
    @endif

    @if ($field->help_text)
        <p id="{{ $id }}-help" class="field-help">{{ $field->help_text }}</p>
    @endif
    <p class="field-error" data-client-error hidden>Please fill in this field.</p>
    @error($errorKey)
        <p id="{{ $id }}-error" class="field-error" role="alert">{{ $message }}</p>
    @enderror
</div>
