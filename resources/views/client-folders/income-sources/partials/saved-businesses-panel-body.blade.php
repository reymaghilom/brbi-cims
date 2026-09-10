<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
        <h2 id="saved-businesses-title" class="ui-section-title">Businesses / Income Sources</h2>
        <p class="mt-0.5 text-xs text-text-muted">Manage existing businesses and complete pending Business Reports.</p>
    </div>
    <div class="flex shrink-0 items-center gap-2">
        <button type="button" class="ui-button-secondary-compact shrink-0" title="Show Recent Activity" aria-label="Show Recent Activity panel" aria-controls="business-history-panel" aria-expanded="false" data-business-history-show hidden><x-ui.icon name="eye" size="size-3.5" />Show Activity</button>
        <button type="button" class="ui-button-primary-compact shrink-0" data-modal-open="add-business-template-dialog"><x-ui.icon name="plus" size="size-3.5" />Add Business</button>
    </div>
</div>

@php($hasAnyBusiness = $businesses->isNotEmpty() || $checkFirstCandidates->isNotEmpty())

@if($hasAnyBusiness)
    <div class="mt-4" data-business-batch-panel>
        <div class="flex flex-col gap-3 border-b border-ui-border pb-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="relative min-w-0 basis-full sm:w-64 sm:basis-auto">
                <label for="business-search" class="sr-only">Search business</label>
                <span class="pointer-events-none absolute inset-y-0 left-0 grid w-9 place-items-center text-text-muted"><x-ui.icon name="search" size="size-4" /></span>
                <input id="business-search" type="search" class="ui-control !min-h-9 !py-1.5 !pl-9 text-sm" placeholder="Search business..." autocomplete="off" data-business-search>
            </div>
            @if($businesses->isNotEmpty())
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <button type="button" class="ui-button-primary-compact" data-business-print-selected disabled><x-ui.icon name="printer" size="size-3.5" />Preview Selected</button>
                    <x-ui.context-menu label="Download selected business reports">
                        <x-slot:trigger>
                            <span class="ui-button-secondary-compact pointer-events-none opacity-55" data-business-download-selected-trigger aria-disabled="true" tabindex="-1"><x-ui.icon name="download" size="size-3.5" />Download Selected<x-ui.icon name="chevron-down" size="size-3.5" /></span>
                        </x-slot:trigger>
                        <button type="button" role="menuitem" data-business-batch-pdf-submit class="client-folder-menu-item"><x-ui.icon name="report" size="size-4" class="text-danger" />Download PDF</button>
                        <button type="button" role="menuitem" data-business-batch-excel-submit class="client-folder-menu-item"><x-ui.icon name="spreadsheet" size="size-4" class="text-success" />Download Excel</button>
                    </x-ui.context-menu>
                    <button type="button" class="ui-button-danger-compact" data-business-delete-selected disabled><x-ui.icon name="trash" size="size-3.5" />Delete Selected</button>
                </div>
            @endif
        </div>

        @if($businesses->isNotEmpty())
            <div class="flex flex-wrap items-center gap-2.5 py-3 text-sm">
                <label class="flex items-center gap-2 font-semibold text-text-main"><input type="checkbox" class="size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary" data-business-select-all>Select All</label>
                <span class="text-text-muted">&bull;</span>
                <span class="font-medium text-text-muted" data-business-selected-count>0 selected</span>
            </div>
        @endif

        <div class="overflow-x-auto overflow-y-hidden rounded-card border border-ui-border {{ $businesses->isEmpty() ? 'mt-3' : '' }}">
            <table class="w-full min-w-[52rem] table-fixed text-left text-sm" data-business-sort-table>
                {{-- Address stays the widest data column and still wraps a long address in full
                     (the cell uses break-words, never truncation), but 40% left a short address
                     stranded far from its CI Date. Narrowing it starts the CI Date column ten
                     points further left, so the two read as related; the freed width is spread
                     across the remaining columns rather than piled onto Business, which is capped
                     deliberately. The total is unchanged, so proportional fill behaves as before. --}}
                <colgroup>
                    <col class="w-10">
                    <col class="w-[22%]">
                    <col class="w-[30%]">
                    <col class="w-[15%]">
                    <col class="w-[14%]">
                    <col class="w-[13%]">
                </colgroup>
                <thead class="bg-surface-subtle text-xs font-bold text-text-muted">
                    <tr>
                        <th scope="col" class="py-3 pl-3 pr-1"><span class="sr-only">Select</span></th>
                        @foreach(['business_name' => 'Business', 'address' => 'Address', 'ci_date' => 'CI Date'] as $column => $label)
                            <th scope="col" class="py-3 {{ $column === 'business_name' ? 'pl-1 pr-3' : 'px-3' }}" aria-sort="none" data-sort-th="{{ $column }}">
                                @if($column === 'address')
                                    {{ $label }}
                                @else
                                    <span class="inline-flex items-stretch gap-1.5">
                                        <button type="button" class="inline-flex items-center gap-1.5 hover:text-brand-sidebar" data-sort-toggle data-sort-key="{{ $column }}" data-sort-type="{{ $column === 'business_name' ? 'text' : 'date' }}">{{ $label }}<span class="inline-flex flex-col text-[0.5rem] leading-[0.4rem]" aria-hidden="true"><span class="opacity-30" data-sort-arrow="asc">▲</span><span class="opacity-30" data-sort-arrow="desc">▼</span></span></button>
                                    </span>
                                @endif
                            </th>
                        @endforeach
                        <th scope="col" class="px-3 py-3">Status</th>
                        <th scope="col" class="px-3 py-3 text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ui-border bg-surface" data-business-table-body>
                    {{-- Report Pending rows first — these need action from the CI. Ordering within
                         each group preserves the existing sort_order/id sequence; nothing is
                         reshuffled by name/address, only the two already-mutually-exclusive
                         collections (by revision) are placed one after another. --}}
                    @foreach($checkFirstCandidates as $candidate)
                        @php($candidateCheck = $candidate->businessCheck)
                        <tr class="align-middle transition hover:bg-surface-subtle/70" data-business-row
                            data-sort-business_name="{{ strtolower($candidate->displayName()) }}"
                            data-sort-address="{{ strtolower($candidateCheck?->location ?? '') }}"
                            data-sort-ci_date="{{ optional($candidateCheck?->ci_date)->format('Y-m-d') ?? '' }}"
                        >
                            <td class="py-3 pl-3 pr-1"><span class="sr-only">Report Pending — not yet selectable for bulk actions</span></td>
                            <td class="py-3 pl-1 pr-3">
                                <p class="break-words font-bold text-text-main">{{ $candidate->displayName() }}</p>
                                <p class="mt-0.5 max-w-56 truncate text-xs text-text-muted">{{ $candidate->template->name }}</p>
                                @if($candidateCheck?->investigator && (int) $candidateCheck->investigator->id !== (int) auth()->id())
                                    <p class="mt-0.5 text-xs leading-5 text-text-muted">Business Check created by: {{ $candidateCheck->investigator->full_name }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-3 break-words text-xs leading-5 text-text-muted">{{ $candidateCheck?->location ?: '—' }}</td>
                            <td class="px-3 py-3 text-xs leading-5 text-text-muted">{{ $candidateCheck?->ci_date?->format('M j, Y') ?? '—' }}</td>
                            <td class="px-3 py-3">
                                <span class="inline-flex items-center rounded-full bg-progress-soft px-2 py-0.5 text-xs font-bold text-progress">Report Pending</span>
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex items-center justify-center">
                                    <x-ui.context-menu :label="'Actions for '.$candidate->displayName()">
                                        <x-slot:trigger><span class="ui-dots-trigger"><x-ui.icon name="more-vertical" size="size-4" /></span></x-slot:trigger>
                                        <a href="{{ route('client-folders.income-sources.edit', [$clientFolder, $candidate] + $personParams) }}" data-modal-open="business-report-dialog" data-business-report-url="{{ route('client-folders.income-sources.edit', [$clientFolder, $candidate] + $personParams) }}" role="menuitem" class="client-folder-menu-item">Complete Business Report</a>
                                    </x-ui.context-menu>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    @foreach($businesses as $business)
                        <tr class="align-middle transition hover:bg-surface-subtle/70" data-business-row
                            data-sort-business_name="{{ strtolower($business->displayName()) }}"
                            data-sort-address="{{ strtolower($business->businessReport?->main_business_address ?? '') }}"
                            data-sort-ci_date="{{ optional($business->businessReport?->start_date)->format('Y-m-d') ?? '' }}"
                        >
                            <td class="py-3 pl-3 pr-1">
                                <input type="checkbox" class="size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary" data-business-select value="{{ $business->id }}" data-business-name="{{ $business->displayName() }}" aria-label="Select {{ $business->displayName() }}">
                            </td>
                            <td class="py-3 pl-1 pr-3">
                                <p class="break-words font-bold text-text-main" data-business-row-name>{{ $business->displayName() }}</p>
                                <p class="mt-0.5 max-w-56 truncate text-xs text-text-muted">{{ $business->template->name }}</p>
                            </td>
                            <td class="px-3 py-3 break-words text-xs leading-5 text-text-muted">{{ $business->businessReport?->main_business_address ?: '—' }}</td>
                            <td class="px-3 py-3 text-xs leading-5 text-text-muted">{{ optional($business->businessReport?->start_date)->format('M j, Y') ?? '—' }}</td>
                            <td class="px-3 py-3">
                                {{-- Display wording only: this row is already the "explicitly saved Business Report" branch of
                                     the list (see IncomeSourceController::dedicatedSources()'s $requireReport) — the
                                     label reads Completed, the state behind it is untouched. --}}
                                <span class="inline-flex items-center rounded-full bg-success-soft px-2 py-0.5 text-xs font-bold text-success">Completed</span>
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex items-center justify-center gap-1">
                                    <a href="{{ route('client-folders.income-sources.edit', [$clientFolder, $business] + $personParams) }}" data-modal-open="business-report-dialog" data-business-report-url="{{ route('client-folders.income-sources.edit', [$clientFolder, $business] + $personParams) }}" class="ui-button-secondary-compact !size-8 !min-h-8 !px-0" aria-label="Update Business Report for {{ $business->displayName() }}" title="Edit"><x-ui.icon name="edit" size="size-4" /></a>
                                    <x-ui.context-menu :label="'Actions for '.$business->displayName()">
                                        <x-slot:trigger><span class="ui-dots-trigger"><x-ui.icon name="more-vertical" size="size-4" /></span></x-slot:trigger>
                                        <a href="{{ route('client-folders.generated-reports.preview', [$clientFolder, 'report_type' => 'business_income_source', 'income_source_id' => $business->id] + $personParams) }}" target="_blank" rel="noopener" role="menuitem" class="client-folder-menu-item">Preview Report</a>
                                        <a href="{{ route('client-folders.income-sources.export-pdf', [$clientFolder, $business] + $personParams) }}" target="_blank" rel="noopener" role="menuitem" class="client-folder-menu-item">Download PDF</a>
                                        <button type="submit" form="business-{{ $business->id }}-export-excel-form" role="menuitem" class="client-folder-menu-item" data-business-download-submit>Download Excel</button>
                                        <div class="my-1 border-t border-ui-border"></div>
                                        <button type="button" role="menuitem" class="client-folder-menu-item text-danger" data-modal-open="delete-business-{{ $business->id }}">Delete</button>
                                    </x-ui.context-menu>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    <tr data-business-empty-search hidden><td colspan="6" class="px-6 py-10 text-center"><p class="font-semibold text-text-main">No businesses match your search.</p><p class="mt-1 text-sm text-text-muted">Try a different business name.</p></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    @foreach($businesses as $business)
        <form id="business-{{ $business->id }}-export-excel-form" method="POST" action="{{ route('client-folders.income-sources.export-excel', [$clientFolder, $business] + $personParams) }}" hidden>
            @csrf
        </form>
        <x-ui.confirmation-dialog id="delete-business-{{ $business->id }}" title="Permanently Delete Business Report?" :action="route('client-folders.income-sources.business-report.destroy', [$clientFolder, $business] + $personParams)" method="DELETE" confirm-label="Delete Permanently" destructive data-business-delete-form>
            <p class="text-sm text-text-muted">This will permanently delete this Business Report and cannot be undone. The related Business Check, if any, will remain unchanged.</p>
        </x-ui.confirmation-dialog>
    @endforeach

    @if($businesses->isNotEmpty())
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
        <x-ui.modal id="delete-selected-businesses-dialog" title="Permanently delete selected Business Reports?" size="max-w-md">
            <p class="text-sm leading-6 text-text-muted" data-business-delete-selected-body>You are about to permanently delete the selected Business Reports. This action cannot be undone. Existing Business Checks will remain unchanged.</p>
            <p class="mt-3 hidden rounded-control border border-danger/25 bg-danger-soft px-3.5 py-2.5 text-sm text-danger" role="alert" data-business-delete-selected-error></p>
            <x-slot:footer>
                <button type="button" class="ui-button-secondary" data-modal-close data-business-delete-selected-cancel>Cancel</button>
                <form method="POST" action="{{ route('client-folders.income-sources.business-report.destroy-selected', [$clientFolder] + $personParams) }}" data-business-delete-selected-form>
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
                    <button type="submit" class="ui-button-danger" data-business-delete-selected-submit>Delete</button>
                </form>
            </x-slot:footer>
        </x-ui.modal>
    @endif
@else
    <div class="mt-4 rounded-control bg-surface-subtle p-4 text-sm leading-6 text-text-muted">
        <p class="font-semibold text-text-main">No businesses yet</p>
        <p class="mt-1">Add a business to begin recording Business / Income Source information.</p>
    </div>
@endif
