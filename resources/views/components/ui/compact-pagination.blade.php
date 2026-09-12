@props(['paginator'])

<nav {{ $attributes->class('flex items-center gap-1') }}>
    <a href="{{ $paginator->previousPageUrl() ?? '#' }}" @class(['ui-action-icon-button', 'pointer-events-none opacity-40' => ! $paginator->previousPageUrl()]) @if(! $paginator->previousPageUrl()) aria-disabled="true" @endif aria-label="Previous page"><x-ui.icon name="chevron-right" size="size-4" class="rotate-180" /></a>
    @foreach($paginator->getUrlRange(1, $paginator->lastPage()) as $page => $url)
        @if($page === 1 || $page === $paginator->lastPage() || abs($page - $paginator->currentPage()) <= 1)
            <a href="{{ $url }}" @if($page === $paginator->currentPage()) aria-current="page" @endif @class(['inline-flex size-9 items-center justify-center rounded-control text-sm font-semibold', 'bg-brand-primary text-white' => $page === $paginator->currentPage(), 'text-text-main hover:bg-surface-muted' => $page !== $paginator->currentPage()])>{{ $page }}</a>
        @elseif(abs($page - $paginator->currentPage()) === 2)
            <span class="px-1 text-text-muted">&hellip;</span>
        @endif
    @endforeach
    <a href="{{ $paginator->nextPageUrl() ?? '#' }}" @class(['ui-action-icon-button', 'pointer-events-none opacity-40' => ! $paginator->nextPageUrl()]) @if(! $paginator->nextPageUrl()) aria-disabled="true" @endif aria-label="Next page"><x-ui.icon name="chevron-right" size="size-4" /></a>
</nav>
