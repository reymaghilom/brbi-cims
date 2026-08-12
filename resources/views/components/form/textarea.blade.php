@props(['name', 'inputName' => null, 'label', 'value' => null, 'rows' => 4, 'help' => null, 'required' => false])
@php($hasError = $errors->has($name))

<div {{ $attributes->only('class') }}>
    <label for="{{ str($name)->replace('.', '-') }}" class="ui-label">{{ $label }} @if($required)<span class="text-danger" aria-hidden="true">*</span><span class="sr-only">required</span>@endif</label>
    <textarea id="{{ str($name)->replace('.', '-') }}" name="{{ $inputName ?? $name }}" rows="{{ $rows }}" @required($required) @if($hasError) aria-invalid="true" aria-describedby="{{ str($name)->replace('.', '-') }}-error" @elseif($help) aria-describedby="{{ str($name)->replace('.', '-') }}-help" @endif {{ $attributes->except(['class'])->class('ui-control') }}>{{ old($name, $value) }}</textarea>
    @if($help)<p id="{{ str($name)->replace('.', '-') }}-help" class="ui-help">{{ $help }}</p>@endif
    <x-form.validation-message :for="$name" />
</div>
