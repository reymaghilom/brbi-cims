@props(['title', 'eyebrow' => null])

<header {{ $attributes->class('mb-7 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between') }}>
    <div class="min-w-0">
        @if ($eyebrow)<p class="mb-1 text-xs font-bold uppercase tracking-[0.16em] text-brand-primary">{{ $eyebrow }}</p>@endif
        <h1 class="ui-page-title">{{ $title }}</h1>
        @isset($description)<div class="mt-2 max-w-3xl text-sm leading-6 text-text-muted">{{ $description }}</div>@endisset
    </div>
    @isset($actions)<div class="flex shrink-0 flex-wrap items-center gap-3">{{ $actions }}</div>@endisset
</header>
