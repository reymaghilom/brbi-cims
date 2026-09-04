{{--
    BRBI-CIMS pagination. Replaces Laravel's stock Tailwind pagination view, which shipped raw
    gray-*/blue-* palette classes plus 19 dark: variants (neither belongs to this design system),
    rendered unavailable arrows without aria-disabled, and printed its own "Showing X to Y of Z
    results" line that duplicated the count line each listing already renders above its grid.

    Every page link still comes from the paginator itself, so an arrow is only ever a real <a>
    when a valid target page exists — on the first/last page Previous/Next render as an inert,
    muted, aria-disabled <span> instead, and no out-of-range URL is generated.
--}}
@if ($paginator->hasPages())
    @php($itemBase = 'inline-flex min-h-9 items-center justify-center rounded-control border px-3 text-sm font-semibold transition')
    @php($itemIdle = $itemBase.' border-ui-border bg-surface text-text-main hover:border-ui-border-strong hover:bg-surface-muted')
    @php($itemDisabled = $itemBase.' cursor-not-allowed border-ui-border bg-surface-muted text-text-subtle')
    @php($itemActive = $itemBase.' border-brand-primary bg-brand-primary text-white')

    <nav role="navigation" aria-label="Pagination Navigation" class="flex flex-wrap items-center justify-center gap-1.5">
        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span class="{{ $itemDisabled }}" aria-disabled="true" aria-label="Previous page">
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $itemIdle }}" aria-label="Previous page">
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
            </a>
        @endif

        {{-- Page numbers --}}
        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="{{ $itemDisabled }}" aria-disabled="true">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="{{ $itemActive }}" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="{{ $itemIdle }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $itemIdle }}" aria-label="Next page">
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg>
            </a>
        @else
            <span class="{{ $itemDisabled }}" aria-disabled="true" aria-label="Next page">
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg>
            </span>
        @endif
    </nav>
@endif
