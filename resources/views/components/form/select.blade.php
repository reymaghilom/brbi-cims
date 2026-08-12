@props(['name', 'inputName' => null, 'label', 'options' => [], 'selected' => null, 'placeholder' => null, 'help' => null, 'required' => false])
@php($hasError = $errors->has($name))

<div {{ $attributes->only('class') }}>
    <label for="{{ str($name)->replace('.', '-') }}" class="ui-label">{{ $label }} @if($required)<span class="text-danger" aria-hidden="true">*</span><span class="sr-only">required</span>@endif</label>
    <select id="{{ str($name)->replace('.', '-') }}" name="{{ $inputName ?? $name }}" @required($required) @if($hasError) aria-invalid="true" aria-describedby="{{ str($name)->replace('.', '-') }}-error" @elseif($help) aria-describedby="{{ str($name)->replace('.', '-') }}-help" @endif {{ $attributes->except(['class'])->class('ui-control') }}>
        @if($placeholder)<option value="">{{ $placeholder }}</option>@endif
        @foreach($options as $value => $optionLabel)<option value="{{ $value }}" @selected((string) old($name, $selected) === (string) $value)>{{ $optionLabel }}</option>@endforeach
    </select>
    @if($help)<p id="{{ str($name)->replace('.', '-') }}-help" class="ui-help">{{ $help }}</p>@endif
    <x-form.validation-message :for="$name" />
</div>
