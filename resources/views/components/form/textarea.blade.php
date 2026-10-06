@props(['name', 'label', 'optional' => false, 'required' => false, 'help' => null, 'value' => null, 'rows' => 4, 'errorKey' => null])
@php
    $id = $attributes->get('id', 'f-'.\Illuminate\Support\Str::slug(str_replace(['[', ']', '.'], '-', $name)));
    $errorKey ??= trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $hasError = $errors->has($errorKey);
    $describedBy = trim(($help ? $id.'-help ' : '').($hasError ? $id.'-error' : ''));
@endphp
<div>
    <label for="{{ $id }}" class="field-label">{{ $label }}@if ($required)<span class="text-coral" aria-hidden="true"> *</span>@elseif ($optional)<span class="tag-optional">(optional)</span>@endif</label>
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}"
              {{ $attributes->except('id')->merge(['class' => 'field-input resize-y leading-relaxed']) }}
              @if ($required) required aria-required="true" @endif
              @if ($hasError) aria-invalid="true" @endif
              @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>{{ old($errorKey, $value) }}</textarea>
    @if ($help)
        <p id="{{ $id }}-help" class="field-help">{{ $help }}</p>
    @endif
    @error($errorKey)
        <p id="{{ $id }}-error" class="field-error" role="alert">{{ $message }}</p>
    @enderror
</div>
