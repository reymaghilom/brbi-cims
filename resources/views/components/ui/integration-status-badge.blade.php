@props(['status', 'provider' => null])

<span {{ $attributes->class('inline-flex items-center gap-2 rounded-full border border-ui-border bg-surface px-2.5 py-1 text-xs font-bold') }}>
    @if($provider)<span class="text-text-muted">{{ $provider }}</span><span aria-hidden="true">·</span>@endif
    <x-ui.status-badge :status="$status" class="-my-1 -mr-2" />
</span>
