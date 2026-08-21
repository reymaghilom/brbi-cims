@extends('layouts.app')

@section('title', 'Business / Income Sources · '.$clientFolder->display_name)

@section('content')
    @php
        $personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null);
        $sortUrl = fn (string $column) => route('client-folders.income-sources.manage', [$clientFolder] + $personParams + [
            'sort' => $column,
            'direction' => ($sort === $column && $sortDirection === 'asc') ? 'desc' : 'asc',
        ]);
        $sortOptions = [
            ['column' => 'ci_date', 'direction' => 'asc', 'label' => 'CI Date (Oldest first)'],
            ['column' => 'ci_date', 'direction' => 'desc', 'label' => 'CI Date (Newest first)'],
            ['column' => 'business_name', 'direction' => 'asc', 'label' => 'Business Name (A–Z)'],
            ['column' => 'business_name', 'direction' => 'desc', 'label' => 'Business Name (Z–A)'],
            ['column' => 'year_established', 'direction' => 'asc', 'label' => 'Year Established (Oldest first)'],
            ['column' => 'year_established', 'direction' => 'desc', 'label' => 'Year Established (Newest first)'],
        ];
    @endphp

    <x-ui.breadcrumb :items="[
        ['label' => 'Client Folders', 'url' => route('client-folders.index')],
        ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', [$clientFolder] + $personParams)],
        ['label' => 'Business / Income Sources'],
    ]" />

    <x-ui.page-header title="Business / Income Sources">
        <x-slot:description>List of the client's businesses and sources of income.</x-slot:description>
        <x-slot:actions><button type="button" data-modal-open="add-business-template-dialog" class="ui-button-primary"><x-ui.icon name="plus" size="size-4" />{{ $businesses->isEmpty() ? 'Add Business' : 'Add Another Business' }}</button></x-slot:actions>
    </x-ui.page-header>

    @error('income_source')<div class="mb-6 rounded-card border border-danger/30 bg-danger-soft p-4 text-sm font-semibold text-danger" role="alert">{{ $message }}</div>@enderror

    @if($businesses->isNotEmpty())
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(15rem,23%)]" data-business-batch-panel>
            <section class="ui-panel mb-6 p-4 sm:p-5 xl:mb-0" aria-labelledby="saved-businesses-title">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="saved-businesses-title" class="ui-section-title">Saved Businesses / Income Sources</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="ui-button-primary-compact" data-business-print-selected disabled><x-ui.icon name="printer" size="size-3.5" />Print Selected</button>
                        <x-ui.context-menu label="Download selected business reports">
                            <x-slot:trigger>
                                <span class="ui-button-secondary-compact pointer-events-none opacity-55" data-business-download-selected-trigger aria-disabled="true" tabindex="-1"><x-ui.icon name="download" size="size-3.5" />Download Selected<x-ui.icon name="chevron-down" size="size-3.5" /></span>
                            </x-slot:trigger>
                            <button type="button" role="menuitem" data-business-batch-pdf-submit class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="report" size="size-4" class="text-danger" />Download PDF</button>
                            <button type="button" role="menuitem" data-business-batch-excel-submit class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="spreadsheet" size="size-4" class="text-success" />Download Excel</button>
                        </x-ui.context-menu>
                    </div>
                </div>

                <div class="mb-3 flex flex-wrap items-center gap-2.5 text-sm">
                    <label class="flex items-center gap-2 font-semibold text-text-main"><input type="checkbox" class="size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary" data-business-select-all>Select All</label>
                    <span class="text-text-muted">&bull;</span>
                    <span class="font-medium text-text-muted" data-business-selected-count>0 selected</span>
                </div>

                <div class="mb-3 sm:hidden">
                    <label for="business-sort-select" class="ui-label">Sort by</label>
                    <select id="business-sort-select" class="ui-control" data-business-sort-select>
                        <option value="{{ route('client-folders.income-sources.manage', [$clientFolder] + $personParams) }}" @selected(!$sort)>Default order</option>
                        @foreach($sortOptions as $option)
                            <option value="{{ route('client-folders.income-sources.manage', [$clientFolder] + $personParams + ['sort' => $option['column'], 'direction' => $option['direction']]) }}" @selected($sort === $option['column'] && $sortDirection === $option['direction'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="hidden overflow-x-auto rounded-card border border-ui-border sm:block">
                    <table class="w-full table-fixed divide-y divide-ui-border text-left text-sm">
                        <colgroup>
                            <col class="w-10">
                            <col class="w-[16%]">
                            <col class="w-[35%]">
                            <col class="w-[22%]">
                            <col class="w-[27%]">
                        </colgroup>
                        <thead class="border-b border-ui-border bg-surface-muted text-xs font-semibold uppercase tracking-wide text-text-muted">
                            <tr>
                                <th class="px-4 py-3"><span class="sr-only">Select</span></th>
                                @foreach(['ci_date' => 'CI Date', 'business_name' => 'Business Name', 'year_established' => 'Year Established'] as $column => $label)
                                    <th class="px-4 py-3 tracking-normal">
                                        <a href="{{ $sortUrl($column) }}" class="inline-flex items-center gap-1 transition hover:text-brand-primary focus-visible:text-brand-primary @if($sort === $column) text-brand-primary @endif" aria-label="Sort by {{ $label }}, {{ $sort === $column ? ($sortDirection === 'asc' ? 'currently ascending, click for descending' : 'currently descending, click for ascending') : 'click to sort ascending' }}">
                                            {{ $label }}
                                            @if($sort === $column)
                                                <x-ui.icon :name="$sortDirection === 'asc' ? 'chevron-up' : 'chevron-down'" size="size-3" />
                                            @endif
                                        </a>
                                    </th>
                                @endforeach
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ui-border">
                            @foreach($businesses as $business)
                                <tr class="transition hover:bg-surface-muted">
                                    <td class="px-4 py-3.5 align-middle">
                                        <input type="checkbox" class="size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary" data-business-select value="{{ $business->id }}" data-business-name="{{ $business->displayName() }}" aria-label="Select {{ $business->displayName() }}">
                                    </td>
                                    <td class="px-4 py-3.5 align-middle text-sm text-text-muted">{{ optional($business->businessReport?->start_date)->format('M j, Y') ?? '—' }}</td>
                                    <td class="px-4 py-3.5 align-middle">
                                        <span class="min-w-0 line-clamp-2 text-sm font-medium leading-snug text-text-main" title="{{ $business->displayName() }}">{{ $business->displayName() }}</span>
                                    </td>
                                    <td class="px-4 py-3.5 align-middle text-sm text-text-muted">{{ $business->businessReport?->year_established ?? '—' }}</td>
                                    <td class="px-4 py-3.5 align-middle">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <a href="{{ route('client-folders.income-sources.edit', [$clientFolder, $business] + $personParams) }}" data-modal-open="business-report-dialog" data-business-report-url="{{ route('client-folders.income-sources.edit', [$clientFolder, $business] + $personParams) }}" class="ui-action-icon-button ui-action-icon-button-neutral" title="Edit business" aria-label="Edit {{ $business->displayName() }}"><x-ui.icon name="edit" size="size-4" /></a>
                                            <a href="{{ route('client-folders.generated-reports.preview', [$clientFolder, 'report_type' => 'business_income_source', 'income_source_id' => $business->id] + $personParams) }}" target="_blank" rel="noopener" class="ui-action-icon-button ui-action-icon-button-neutral" title="Print business" aria-label="Print {{ $business->displayName() }}"><x-ui.icon name="printer" size="size-4" /></a>
                                            <x-ui.context-menu label="Download {{ $business->displayName() }}">
                                                <x-slot:trigger><span class="ui-action-icon-button ui-action-icon-button-neutral group-open:border-brand-primary group-open:bg-brand-soft group-open:text-brand-primary" title="Download business report"><x-ui.icon name="download" size="size-4" /></span></x-slot:trigger>
                                                <button type="submit" form="business-{{ $business->id }}-export-pdf-form" role="menuitem" data-business-download-submit class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary disabled:cursor-not-allowed disabled:opacity-55"><x-ui.icon name="report" size="size-4" class="text-danger" /><span data-download-label>Download PDF</span></button>
                                                <button type="submit" form="business-{{ $business->id }}-export-excel-form" role="menuitem" data-business-download-submit class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary disabled:cursor-not-allowed disabled:opacity-55"><x-ui.icon name="spreadsheet" size="size-4" class="text-success" /><span data-download-label>Download Excel</span></button>
                                            </x-ui.context-menu>
                                            <button type="button" data-modal-open="delete-business-{{ $business->id }}" class="ui-action-icon-button ui-action-icon-button-danger" title="Delete business" aria-label="Delete {{ $business->displayName() }}"><x-ui.icon name="trash" size="size-4" /></button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="grid gap-3 sm:hidden">
                    @foreach($businesses as $business)
                        <article class="ui-card p-4">
                            <div class="flex min-w-0 items-start gap-3">
                                <input type="checkbox" class="mt-1 size-4 shrink-0 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary" data-business-select value="{{ $business->id }}" data-business-name="{{ $business->displayName() }}" aria-label="Select {{ $business->displayName() }}">
                                <h3 class="min-w-0 line-clamp-2 text-sm font-medium leading-snug text-text-main" title="{{ $business->displayName() }}">{{ $business->displayName() }}</h3>
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-x-5 gap-y-3 text-sm">
                                <div><dt class="text-text-muted">CI Date</dt><dd class="text-text-muted">{{ optional($business->businessReport?->start_date)->format('M j, Y') ?? '—' }}</dd></div>
                                <div><dt class="text-text-muted">Year Established</dt><dd class="text-text-muted">{{ $business->businessReport?->year_established ?? '—' }}</dd></div>
                            </dl>
                            <div class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-ui-border pt-3">
                                <a href="{{ route('client-folders.income-sources.edit', [$clientFolder, $business] + $personParams) }}" data-modal-open="business-report-dialog" data-business-report-url="{{ route('client-folders.income-sources.edit', [$clientFolder, $business] + $personParams) }}" class="ui-action-icon-button ui-action-icon-button-neutral" title="Edit business" aria-label="Edit {{ $business->displayName() }}"><x-ui.icon name="edit" size="size-4" /></a>
                                <a href="{{ route('client-folders.generated-reports.preview', [$clientFolder, 'report_type' => 'business_income_source', 'income_source_id' => $business->id] + $personParams) }}" target="_blank" rel="noopener" class="ui-action-icon-button ui-action-icon-button-neutral" title="Print business" aria-label="Print {{ $business->displayName() }}"><x-ui.icon name="printer" size="size-4" /></a>
                                <x-ui.context-menu label="Download {{ $business->displayName() }}">
                                    <x-slot:trigger><span class="ui-action-icon-button ui-action-icon-button-neutral group-open:border-brand-primary group-open:bg-brand-soft group-open:text-brand-primary" title="Download business report"><x-ui.icon name="download" size="size-4" /></span></x-slot:trigger>
                                    <button type="submit" form="business-{{ $business->id }}-export-pdf-form" role="menuitem" data-business-download-submit class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary disabled:cursor-not-allowed disabled:opacity-55"><x-ui.icon name="report" size="size-4" class="text-danger" /><span data-download-label>Download PDF</span></button>
                                    <button type="submit" form="business-{{ $business->id }}-export-excel-form" role="menuitem" data-business-download-submit class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary disabled:cursor-not-allowed disabled:opacity-55"><x-ui.icon name="spreadsheet" size="size-4" class="text-success" /><span data-download-label>Download Excel</span></button>
                                </x-ui.context-menu>
                                <button type="button" data-modal-open="delete-business-{{ $business->id }}" class="ui-action-icon-button ui-action-icon-button-danger" title="Delete business" aria-label="Delete {{ $business->displayName() }}"><x-ui.icon name="trash" size="size-4" /></button>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            <aside class="h-fit rounded-panel border border-ui-border bg-surface-muted p-4 shadow-card xl:sticky xl:top-20" aria-labelledby="print-summary-title">
                <div class="mb-2.5 flex items-center gap-2">
                    <span class="text-brand-primary"><x-ui.icon name="report" size="size-4" /></span>
                    <h2 id="print-summary-title" class="text-sm font-semibold text-brand-sidebar">Print Summary</h2>
                </div>

                <p class="text-sm font-semibold text-brand-primary" data-business-selected-summary-count>0 reports selected</p>
                <p class="mt-1 text-xs leading-5 text-text-muted" data-business-selected-empty>Select one or more saved businesses to print or download them together.</p>

                <ol class="mt-3 space-y-1.5 text-sm text-text-main" data-business-selected-list hidden></ol>

                <div class="mt-3 hidden rounded-control bg-surface p-2.5 text-xs leading-5 text-text-muted" data-business-selected-estimate-box>
                    <p class="font-semibold text-text-main">Estimated output</p>
                    <p class="mt-0.5" data-business-selected-estimate></p>
                </div>

                <div class="mt-3 rounded-control border border-ui-border bg-surface p-2.5">
                    <p class="flex items-center gap-1.5 text-xs font-semibold text-text-main"><x-ui.icon name="warning" size="size-3.5" />Printing Tip</p>
                    <p class="mt-1 text-xs leading-5 text-text-muted">For best results, keep each selected report short enough that it can share a page — combined output flows short reports together and starts a new page automatically once one no longer fits.</p>
                </div>
            </aside>
        </div>

        @foreach($businesses as $business)
            <form id="business-{{ $business->id }}-export-pdf-form" method="POST" action="{{ route('client-folders.income-sources.export-pdf', [$clientFolder, $business]) }}" target="_blank" hidden>
                @csrf
            </form>
            <form id="business-{{ $business->id }}-export-excel-form" method="POST" action="{{ route('client-folders.income-sources.export-excel', [$clientFolder, $business]) }}" hidden>
                @csrf
            </form>
            <x-ui.confirmation-dialog id="delete-business-{{ $business->id }}" title="Delete {{ $business->displayName() }}?" :action="route('client-folders.income-sources.destroy', [$clientFolder, $business])" method="DELETE" confirm-label="Delete Permanently" destructive>
                <p class="text-sm text-text-muted">Are you sure you want to permanently delete this business? This action cannot be undone.</p>
            </x-ui.confirmation-dialog>
        @endforeach

        <form id="business-batch-print-form" method="POST" action="{{ route('client-folders.income-sources.batch-print', $clientFolder) }}" target="_blank" hidden>
            @csrf
            <input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
        </form>
        <form id="business-batch-export-pdf-form" method="POST" action="{{ route('client-folders.income-sources.batch-export-pdf', $clientFolder) }}" hidden>
            @csrf
            <input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
        </form>
        <form id="business-batch-export-excel-form" method="POST" action="{{ route('client-folders.income-sources.batch-export-excel', $clientFolder) }}" hidden>
            @csrf
            <input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
        </form>
    @endif

    @if($businesses->isEmpty())
        <x-ui.empty-state title="No businesses saved yet" description="Use the Add Business button above to start encoding a Business / Income Source for this client folder." icon="folder" />
    @endif

    <x-ui.modal id="add-business-template-dialog" title="Add Business" description="Select a business template to continue." size="max-w-lg" data-add-business-dialog>
        <div>
            <label for="add-business-template-select" class="ui-label">Business Template <span class="text-danger" aria-hidden="true">*</span><span class="sr-only">required</span></label>
            <select id="add-business-template-select" class="ui-control" data-add-business-template-select>
                <option value="">Select a business template</option>
                @foreach($businessTemplates as $template)
                    <option value="{{ $template->id }}">{{ $template->name }}</option>
                @endforeach
            </select>
            <p class="mt-2 flex items-start gap-1.5 text-sm font-medium text-danger" role="alert" data-add-business-template-error hidden><x-ui.icon name="warning" size="mt-0.5 size-4" />Please select a business template to continue.</p>
        </div>
        <x-slot:footer>
            <button type="button" class="ui-button-secondary" data-modal-close>Cancel</button>
            <button type="button" class="ui-button-primary" data-add-business-next>Next</button>
        </x-slot:footer>
    </x-ui.modal>

    <a id="business-report-trigger" hidden data-modal-open="business-report-dialog" data-business-report-url="{{ route('client-folders.income-sources.index', [$clientFolder] + $personParams) }}" data-business-report-base-url="{{ route('client-folders.income-sources.index', [$clientFolder] + $personParams) }}"></a>

    <x-ui.business-report-modal />
@endsection
