@props(['for'])

@error($for)
    <p id="{{ str($for)->replace('.', '-') }}-error" {{ $attributes->class('mt-2 flex items-start gap-1.5 text-sm font-medium text-danger') }} role="alert"><x-ui.icon name="warning" size="mt-0.5 size-4" />{{ $message }}</p>
@enderror
