@props(['name', 'inputName' => null, 'label', 'labelGuide' => null, 'type' => 'text', 'value' => null, 'help' => null, 'required' => false])
@php($hasError = $errors->has($name))

<div {{ $attributes->only('class') }}>
    <label for="{{ str($name)->replace('.', '-') }}" class="ui-label">@if($labelGuide)<span>{{ $label }}</span><small class="business-report-column-guide">{{ $labelGuide }}</small>@else{{ $label }}@endif @if($required)<span class="text-danger" aria-hidden="true">*</span><span class="sr-only">required</span>@endif</label>
    <input id="{{ str($name)->replace('.', '-') }}" name="{{ $inputName ?? $name }}" type="{{ $type }}" @if($type !== 'password') value="{{ old($name, $value) }}" @endif @required($required) @if($hasError) aria-invalid="true" aria-describedby="{{ str($name)->replace('.', '-') }}-error" @elseif($help) aria-describedby="{{ str($name)->replace('.', '-') }}-help" @endif {{ $attributes->except(['class'])->class('ui-control') }}>
    @if($help)<p id="{{ str($name)->replace('.', '-') }}-help" class="ui-help">{{ $help }}</p>@endif
    <x-form.validation-message :for="$name" />
</div>
