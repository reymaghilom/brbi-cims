@props(['title', 'open' => false])

<details @if($open) open @endif {{ $attributes->class('ui-card group overflow-hidden') }}>
    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 px-5 py-3 font-bold [&::-webkit-details-marker]:hidden">{{ $title }}<x-ui.icon name="chevron-right" size="size-4 transition group-open:rotate-90" /></summary>
    <div class="border-t border-ui-border px-5 py-4 text-sm leading-6 text-text-muted">{{ $slot }}</div>
</details>
