@extends('layouts.app')

@section('title', 'Reports')

@php
    use Carbon\CarbonImmutable;

    $tab = $filters['tab'] ?? 'all';
    $displayTimezone = config('cims.display_timezone');
    $month = CarbonImmutable::now($displayTimezone);

    $tabs = [
        'all' => 'All Reports',
        'pending' => 'Pending',
        'completed' => 'Completed',
    ];
    // Switching a tab keeps the search and every other filter that is already applied.
    $tabQuery = fn (string $value) => array_filter(['tab' => $value] + collect($filters)->except('tab')->all(), fn ($item) => filled($item));

    $hasFilters = collect($filters)->except('tab')->filter(fn ($value) => filled($value))->isNotEmpty();

    // One compact trigger stands in for the two date fields. It reads back whichever half of the
    // range is actually set, so the toolbar stays narrow without hiding what is filtering the list.
    $fromDate = filled($filters['from'] ?? null) ? CarbonImmutable::parse($filters['from']) : null;
    $toDate = filled($filters['to'] ?? null) ? CarbonImmutable::parse($filters['to']) : null;
    $dateRangeLabel = match (true) {
        $fromDate && $toDate => $fromDate->year === $toDate->year
            ? $fromDate->format('M j').' – '.$toDate->format('M j, Y')
            : $fromDate->format('M j, Y').' – '.$toDate->format('M j, Y'),
        (bool) $fromDate => 'From '.$fromDate->format('M j, Y'),
        (bool) $toDate => 'Until '.$toDate->format('M j, Y'),
        default => 'Select Date Range',
    };
    $hasDateRange = $fromDate !== null || $toDate !== null;
    $dateRangeInvalid = $errors->has('from') || $errors->has('to');
    // Clearing keeps every other active filter — it drops only the two date parameters.
    $clearDatesQuery = collect($filters)->except(['from', 'to'])->filter(fn ($value) => filled($value))->all();
@endphp

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'Reports']]" />

    <section class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-6" aria-labelledby="reports-title">
        <div class="flex min-w-0 items-start gap-3.5">
            <span class="grid size-12 shrink-0 place-items-center rounded-card bg-brand-soft text-brand-primary" aria-hidden="true"><x-ui.icon name="report" size="size-6" /></span>
            <div class="min-w-0">
                <h1 id="reports-title" class="text-2xl font-bold tracking-tight text-text-main sm:text-3xl">Reports</h1>
                <p class="mt-1 text-sm text-text-muted">View pending and completed credit investigation reports.</p>
            </div>
        </div>
        {{-- A report is always started inside one Client Folder, for one exact person and — for the
             two business modules — one exact business. There is deliberately no global generator
             here: the CTA leads into the existing folder workflow instead of duplicating it. --}}
        <a href="{{ route('client-folders.index') }}" class="ui-button-primary w-full shrink-0 sm:w-auto">
            <x-ui.icon name="folder" size="size-4" />Open Client Folders
        </a>
    </section>

    <section class="mt-5" aria-labelledby="reports-summary-title">
        <h2 id="reports-summary-title" class="sr-only">Report summary</h2>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-reports-summary>
            @include('reports._kpis', ['summary' => $summary])
        </div>
    </section>

    {{-- Tabs scroll horizontally rather than wrapping on a narrow screen. --}}
    <nav class="mt-4 -mb-px flex gap-1 overflow-x-auto border-b border-ui-border" aria-label="Report status" data-reports-tabs>
        @foreach($tabs as $value => $label)
            <a href="{{ route('reports.index', $tabQuery($value)) }}"
               data-reports-tab="{{ $value }}"
               @if($tab === $value) aria-current="page" @endif
               class="whitespace-nowrap border-b-2 px-3.5 py-2.5 text-sm font-semibold transition {{ $tab === $value ? 'border-brand-primary text-brand-primary' : 'border-transparent text-text-muted hover:text-text-main' }}">{{ $label }}</a>
        @endforeach
    </nav>

    {{-- Filters are plain GET fields, so a filtered view is bookmarkable and survives pagination. --}}
    <form method="GET" action="{{ route('reports.index') }}" class="ui-card mt-4 p-3 sm:p-4" data-reports-filters>
        <h2 class="sr-only">Filter reports</h2>
        {{-- One toolbar row. The search absorbs the leftover width while every other control keeps
             a fixed, predictable size, so the bar reads the same on every screen it fits on. It
             stacks on phones, wraps into two tidy rows where there genuinely is not room, and sits
             on a single line from xl up — never with Apply/Reset banished to their own row. --}}
        <div class="flex flex-col gap-2.5 sm:flex-row sm:flex-wrap sm:items-center sm:gap-2 xl:flex-nowrap">
            {{-- Client-name search with suggestions. The input is a plain GET field, so submitting
                 it without JavaScript filters by name exactly as before; app.js upgrades it into a
                 combobox that offers accessible clients and, on selection, pins the exact folder
                 through the hidden client_folder_id below. --}}
            <div class="relative min-w-0 flex-1 sm:min-w-56 xl:min-w-60" data-reports-client-search data-suggest-url="{{ route('reports.client-suggestions') }}">
                <label for="reports-search" class="sr-only">Search client name</label>
                <span class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-text-subtle" aria-hidden="true"><x-ui.icon name="search" size="size-4" /></span>
                <input id="reports-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search client name..." class="ui-control !pl-9"
                       role="combobox" aria-expanded="false" aria-controls="reports-client-suggestions" aria-autocomplete="list" autocomplete="off" data-reports-client-input>
                <input type="hidden" name="client_folder_id" value="{{ $filters['client_folder_id'] ?? '' }}" data-reports-client-id>
                <ul id="reports-client-suggestions" role="listbox" aria-label="Client name suggestions" hidden
                    class="absolute inset-x-0 top-full z-30 mt-1 max-h-72 overflow-y-auto rounded-card border border-ui-border bg-surface p-1.5 shadow-float"
                    data-reports-client-suggestions></ul>
            </div>
            <div class="w-full shrink-0 sm:w-40">
                <label for="reports-type" class="sr-only">Report type</label>
                <select id="reports-type" name="report_type" class="ui-control">
                    <option value="">All Report Types</option>
                    @foreach($reportTypes as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['report_type'] ?? null) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-full shrink-0 sm:w-40">
                <label for="reports-person" class="sr-only">Client Type</label>
                <select id="reports-person" name="person" class="ui-control">
                    <option value="">All Client Types</option>
                    <option value="applicant" @selected(($filters['person'] ?? null) === 'applicant')>Applicant</option>
                    <option value="co_maker" @selected(($filters['person'] ?? null) === 'co_maker')>Co-Maker</option>
                </select>
            </div>
            <div class="w-full shrink-0 sm:w-32">
                {{-- The Status filter writes the same `tab` parameter the tabs do, so the two can
                     never disagree about which status the page is showing. --}}
                <label for="reports-status" class="sr-only">Status</label>
                <select id="reports-status" name="tab" class="ui-control">
                    <option value="all" @selected($tab === 'all')>All Statuses</option>
                    <option value="pending" @selected($tab === 'pending')>Pending</option>
                    <option value="completed" @selected($tab === 'completed')>Completed</option>
                </select>
            </div>
            <div class="w-full shrink-0 sm:w-44">
                {{-- One compact control instead of two permanent date fields. It is the project's
                     existing <details data-context-menu> popover, so app.js already handles
                     outside-click and Escape dismissal and aria-expanded — no new JavaScript, and no
                     date-picker library: the native date inputs simply live inside the panel.
                     It re-opens by itself when the submitted range failed validation, so the message
                     is next to the fields that produced it. --}}
                <details data-context-menu data-reports-date-range class="group relative block" @if($dateRangeInvalid) open @endif>
                    <summary class="ui-control flex cursor-pointer list-none items-center gap-2 marker:content-none [&::-webkit-details-marker]:hidden {{ $hasDateRange ? 'border-brand-primary text-brand-primary' : '' }}"
                             aria-haspopup="dialog" aria-expanded="false" title="{{ $dateRangeLabel }}" aria-label="Date range: {{ $dateRangeLabel }}">
                        <x-ui.icon name="calendar" size="size-4" class="shrink-0 text-text-subtle" />
                        <span class="min-w-0 flex-1 truncate text-left">{{ $dateRangeLabel }}</span>
                        <x-ui.icon name="chevron-down" size="size-3.5" class="shrink-0 text-text-subtle" />
                    </summary>
                    <div class="absolute right-0 z-30 mt-2 w-full min-w-64 -translate-y-1 scale-[0.98] rounded-card border border-ui-border bg-surface p-3 opacity-0 shadow-float transition-[opacity,transform] duration-150 ease-in data-[state=open]:translate-y-0 data-[state=open]:scale-100 data-[state=open]:opacity-100 data-[state=open]:ease-out motion-reduce:transform-none motion-reduce:transition-none sm:w-72"
                         data-context-menu-panel role="group" aria-label="Date range">
                        <div class="space-y-2.5">
                            <div>
                                <label for="reports-from" class="mb-1 block text-xs font-semibold text-text-muted">From</label>
                                <input id="reports-from" type="date" name="from" value="{{ old('from', $filters['from'] ?? '') }}" class="ui-control" @if($errors->has('from')) aria-invalid="true" aria-describedby="from-error" @endif>
                            </div>
                            <div>
                                <label for="reports-to" class="mb-1 block text-xs font-semibold text-text-muted">To</label>
                                <input id="reports-to" type="date" name="to" value="{{ old('to', $filters['to'] ?? '') }}" class="ui-control" @if($errors->has('to')) aria-invalid="true" aria-describedby="to-error" @endif>
                            </div>
                        </div>
                        <x-form.validation-message for="from" class="mt-2 text-xs" />
                        <x-form.validation-message for="to" class="mt-2 text-xs" />
                        <div class="mt-3 flex items-center justify-end gap-2 border-t border-ui-border pt-3">
                            <a href="{{ route('reports.index', $clearDatesQuery) }}" class="ui-button-secondary-compact px-2.5">Clear</a>
                            <button type="submit" class="ui-button-primary-compact px-2.5">Apply</button>
                        </div>
                    </div>
                </details>
            </div>

            {{-- Compact filter actions on the same line as the controls they apply, never a
                 page-level CTA. They pair up on a phone and join the toolbar row from tablet up. --}}
            <div class="flex items-center gap-2 sm:contents">
                <button type="submit" class="ui-button-primary-compact shrink-0 px-3">Apply Filters</button>
                {{-- The visible label already distinguishes it from the date popover's own Clear, which drops only
                     the two dates: this one drops every filter and the search. --}}
                @if($hasFilters)<a href="{{ route('reports.index', ['tab' => $tab]) }}" class="ui-button-secondary-compact shrink-0 px-2.5">Clear Filters</a>@endif
            </div>
        </div>
        {{-- The active sort travels with any filter submit, so searching a client never silently
             drops the column the user is sorting by. --}}
        @if($sort)<input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="direction" value="{{ $direction }}">@endif
    </form>

    <section class="ui-card mt-4 overflow-hidden" aria-labelledby="reports-table-title">
        <h2 id="reports-table-title" class="sr-only">Report work items</h2>

        {{-- Sorting replaces just this block rather than reloading the page — see the
             [data-reports-sort] handler in app.js, which reuses the same fragment-fetch approach
             the Dashboard folder browser already uses. Without JavaScript the very same links are
             ordinary GET navigations, so sorting still works. --}}
        <div data-reports-listing>
            @include('reports._listing', ['items' => $items, 'filters' => $filters, 'sort' => $sort, 'direction' => $direction])
        </div>
    </section>
    {{-- The same <dialog>+iframe CI / BI modal the Client Folder uses. It loads the real CI / BI
         page, so the form, its validation, its save endpoint and its audit trail are the existing
         ones — this page adds no second copy of any of them. `stay-on-page` keeps the shared close
         handler from navigating to the Client Folder afterwards: here the save is reflected by
         AUTO-UPDATING the Reports list and KPIs in place instead. --}}
    <x-ui.cibi-report-modal :stay-on-page="true" />

    {{-- The same <dialog>+iframe Business Report modal the Business / Income Sources page uses. It
         loads the real encoding page for one exact income source, so the form, its validation, its
         save endpoint and its audit trail are the existing ones — this page adds no second copy of
         any of them. --}}
    <x-ui.business-report-modal :stay-on-page="true" />

    {{-- The existing Business/Income Sources template selector, shared here only for an unbound
         zero-business work item. Choosing Next opens the authoritative Business Report first-entry
         page; merely opening or cancelling this dialog writes nothing. --}}
    <x-ui.business-template-modal :business-templates="$businessTemplates" />
    <a id="business-report-trigger" hidden data-modal-open="business-report-dialog" data-business-report-url="" data-business-report-base-url=""></a>

    {{-- Residence and Business Checks share this existing iframe modal in the Client Folder
         workflow. Reports supplies only the exact module URL and opts into in-place list/KPI
         refresh after the existing form confirms a successful save. --}}
    <x-ui.check-report-modal :stay-on-page="true" />
@endsection
