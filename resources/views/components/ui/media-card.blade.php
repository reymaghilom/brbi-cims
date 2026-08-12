@props(['title', 'type' => 'photo', 'thumbnail' => null, 'meta' => null])

<article {{ $attributes->class('ui-card overflow-hidden') }}>
    <div class="aspect-[4/3] bg-surface-muted">
        @if($thumbnail)<img src="{{ $thumbnail }}" alt="" class="size-full object-cover">@else<div class="grid size-full place-items-center text-text-subtle"><x-ui.icon name="media" size="size-10" /><span class="sr-only">No preview available</span></div>@endif
    </div>
    <div class="p-4"><div class="flex items-start justify-between gap-3"><h3 class="truncate font-bold">{{ $title }}</h3><span class="rounded-full bg-brand-soft px-2 py-1 text-xs font-bold text-brand-primary">{{ str($type)->title() }}</span></div>@if($meta)<p class="mt-2 text-sm text-text-muted">{{ $meta }}</p>@endif @isset($actions)<div class="mt-4 flex gap-2 border-t border-ui-border pt-3">{{ $actions }}</div>@endisset</div>
</article>
