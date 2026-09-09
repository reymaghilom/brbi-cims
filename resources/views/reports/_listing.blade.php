@php
    /**
     * The listing itself — table, cards and pagination footer. It is rendered both inside the full
     * page and on its own as the response to a sort click, so everything it needs is derived here
     * from $items/$filters rather than passed down from the page.
     */
    $displayTimezone = config('cims.display_timezone');
    $tab = $filters['tab'] ?? 'all';
    $hasFilters = collect($filters)->except('tab')->filter(fn ($value) => filled($value))->isNotEmpty();
    $emptyTitle = match ($tab) {
        'pending' => 'No pending reports.',
        'completed' => 'No completed reports yet.',
        default => 'No report work items available.',
    };
    $emptyDescription = match ($tab) {
        'pending' => 'All available report work items are completed.',
        'completed' => 'Completed reports will appear here once report work is finished.',
        default => 'Report work items appear here as soon as a Client Folder has a report-capable module.',
    };

    // Sorting is server-side over the whole result set, never a reorder of the current page: the
    // key is whitelisted by ReportWorkspaceQuery::SORTS and the direction is validated, so nothing
    // from the request reaches orderBy(). Clicking a column sorts it in its natural first direction
    // (dates newest-first, everything else A→Z) and clicking it again flips.
    $sortColumns = [
        'client' => 'Client',
        'client_type' => 'Client Type',
        'report_type' => 'Report Type',
        'status' => 'Status',
        'updated' => 'Last Updated',
    ];
    $sortLink = function (string $key) use ($filters, $sort, $direction): string {
        $next = $sort === $key
            ? ($direction === 'asc' ? 'desc' : 'asc')
            : ($key === 'updated' ? 'desc' : 'asc');

        // Every active filter rides along, and the page resets to 1 by simply not being carried.
        return route('reports.index', collect($filters)->filter(fn ($value) => filled($value))->all() + ['sort' => $key, 'direction' => $next]);
    };
    $ariaSort = fn (string $key): string => $sort === $key ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none';
@endphp

@if($items->isEmpty())
    <x-ui.empty-state
        class="m-4 sm:m-6"
        :title="$hasFilters ? 'No reports match these filters' : $emptyTitle"
        :description="$hasFilters
            ? 'Try a different search term, report type, client type, status or date range.'
            : $emptyDescription"
        icon="report" />
@else
    @php $rowNumber = $items->firstItem(); @endphp

    {{-- Desktop/laptop: the full approved table. --}}
    <div class="hidden overflow-x-auto lg:block">
        <table class="ui-table">
            <thead>
                <tr>
                    <th scope="col">#</th>
                    @foreach($sortColumns as $key => $label)
                        <th scope="col" aria-sort="{{ $ariaSort($key) }}" data-reports-sort-header="{{ $key }}">
                            <a href="{{ $sortLink($key) }}" class="inline-flex items-center gap-1.5 hover:text-brand-sidebar {{ $sort === $key ? 'text-brand-primary' : '' }}" data-reports-sort="{{ $key }}">{{ $label }}<span class="inline-flex flex-col text-[0.5rem] leading-[0.4rem]" aria-hidden="true"><span class="{{ $sort === $key && $direction === 'asc' ? '' : 'opacity-30' }}" data-sort-arrow="asc">▲</span><span class="{{ $sort === $key && $direction === 'desc' ? '' : 'opacity-30' }}" data-sort-arrow="desc">▼</span></span></a>
                        </th>
                    @endforeach
                    {{-- Right-aligned to sit directly over the action controls, which the row
                         renders justify-end in this same w-px column. --}}
                    <th scope="col" class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    @include('reports._row', ['item' => $item, 'rowNumber' => $rowNumber++, 'displayTimezone' => $displayTimezone])
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Below that the same rows become cards, so a narrow screen never has to scroll a
         wide table sideways to reach the actions. --}}
    <ul class="divide-y divide-ui-border lg:hidden">
        @foreach($items as $item)
            @include('reports._card', ['item' => $item, 'displayTimezone' => $displayTimezone])
        @endforeach
    </ul>

    <footer class="flex flex-col gap-3 border-t border-ui-border bg-surface-muted px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5">
        <p class="text-sm text-text-muted">Showing {{ $items->firstItem() }} to {{ $items->lastItem() }} of {{ $items->total() }} {{ Str::plural('report', $items->total()) }}</p>
        @if($items->hasPages())<nav aria-label="Reports pagination" data-reports-pagination>{{ $items->onEachSide(1)->links() }}</nav>@endif
    </footer>
@endif
