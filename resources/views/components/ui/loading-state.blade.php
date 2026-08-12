@props(['label' => 'Loading'])

<div {{ $attributes->class('ui-card flex items-center justify-center gap-3 px-6 py-10 text-sm font-semibold text-text-muted') }} role="status">
    <span class="size-5 animate-spin rounded-full border-2 border-ui-border border-t-brand-primary motion-reduce:animate-none" aria-hidden="true"></span>
    <span>{{ $label }}</span>
</div>
