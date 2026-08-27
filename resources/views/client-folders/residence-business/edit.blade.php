@extends('layouts.app')

@section('title', 'Residence & Business Report')

@section('content')
    @php
        $personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null);
        $personName = $activePerson?->full_name ?? $clientFolder->display_name;
        $totalChecks = $residenceChecks->count() + $businessChecks->count();
        // 'person' was dropped from both tables below (this page already identifies the active
        // Applicant/Co-Maker, so a per-row column would be redundant) — these arrays only feed
        // each table's own header/sort columns.
        $residenceSortableColumns = ['ci_date' => ['label' => 'CI Date', 'type' => 'date'], 'location' => ['label' => 'Location', 'type' => 'text'], 'ci' => ['label' => 'CI', 'type' => 'text']];
        $sortableColumns = ['ci_date' => ['label' => 'CI Date', 'type' => 'date'], 'business_subject' => ['label' => 'Business / Subject', 'type' => 'text'], 'location' => ['label' => 'Location', 'type' => 'text'], 'ci' => ['label' => 'CI', 'type' => 'text']];
    @endphp
    <x-ui.breadcrumb :items="[['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', [$clientFolder] + $personParams)], ['label' => 'Residence & Business Report']]" />

    {{-- No page-specific status banner here — layouts.app's own <main>-level toast (session('status')
         via <x-ui.toast>, auto-dismissing) already covers every save/update/delete redirect that
         lands on this page. A second, persistent (never auto-dismissing) banner used to render here
         too, duplicating that same message and never going away on its own. --}}
    @if(session('status') && session('statusType', 'success') === 'success')
        {{-- Business Check's Save/Update is a plain form POST + server redirect landing right back
             here — unlike Residence Check's own XHR + brbi:check-saved postMessage, which never
             navigates its iframe at all. Without this marker, a successful Business Check save
             left the check-report-dialog's iframe simply displaying this whole page nested inside
             itself: the save worked, but from the user's side nothing closed and the modal looked
             broken/stuck. The message/status-type are carried straight through the postMessage
             itself (app.js stashes them in sessionStorage for the parent's own reload to pick up) —
             deliberately not re-flashed via session()->keep() here, since that would leave this
             same status sitting around for the parent's reload to independently render a second
             time through its own generic session('status') toast (layouts.app), i.e. a duplicate. --}}
        <span hidden data-check-saved-notify
              data-check-saved-return-url="{{ route('client-folders.residence-business.edit', [$clientFolder] + $personParams) }}"
              data-check-saved-message="{{ session('status') }}"
              data-check-saved-status-type="{{ session('statusType', 'success') }}"></span>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(180px,210px)]" data-check-batch-panel>
        <div class="min-w-0">
            <div class="mb-5 rounded-card border border-ui-border bg-surface p-4 shadow-card">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm font-semibold text-text-main"><span data-check-selected-count>0</span> of {{ $totalChecks }} selected</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="ui-button-secondary-compact" data-check-select-all><x-ui.icon name="check" size="size-3.5" />Select All</button>
                        <button type="button" class="ui-button-primary-compact" data-check-print-selected disabled><x-ui.icon name="printer" size="size-3.5" />Print Selected</button>
                        <x-ui.context-menu label="Download selected checks">
                            <x-slot:trigger>
                                <span class="ui-button-secondary-compact pointer-events-none opacity-55" data-check-download-selected-trigger aria-disabled="true" tabindex="-1"><x-ui.icon name="download" size="size-3.5" />Download Selected<x-ui.icon name="chevron-down" size="size-3.5" /></span>
                            </x-slot:trigger>
                            <button type="button" role="menuitem" data-check-batch-pdf-submit class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="report" size="size-4" class="text-danger" />Download PDF</button>
                            <button type="button" role="menuitem" data-check-batch-docx-submit class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="report" size="size-4" class="text-brand-primary" />Download Word</button>
                        </x-ui.context-menu>
                        <button type="button" class="ui-button-secondary-compact" data-check-clear-selection><x-ui.icon name="close" size="size-3.5" />Clear Selection</button>
                    </div>
                </div>
            </div>

            {{-- Residence Check --}}
            <section class="ui-panel mb-5 p-4 sm:p-5" aria-labelledby="residence-checks-title">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <span class="grid size-8 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-primary"><x-ui.icon name="home" size="size-4" /></span>
                        <h2 id="residence-checks-title" class="ui-section-title">Residence Check</h2>
                    </div>
                    <a href="{{ route('client-folders.residence-checks.create', [$clientFolder] + $personParams) }}" data-modal-open="check-report-dialog" data-check-report-url="{{ route('client-folders.residence-checks.create', [$clientFolder] + $personParams) }}" data-check-report-title="Residence Check" class="ui-button-primary-compact"><x-ui.icon name="plus" size="size-3.5" />Add Residence Check</a>
                </div>

                <div class="overflow-x-auto rounded-card border border-ui-border">
                    <table class="w-full min-w-[600px] table-fixed divide-y divide-ui-border text-left text-sm" data-check-sort-table>
                            <colgroup>
                                <col class="w-10">
                                <col class="w-[20%]">
                                <col class="w-[50%]">
                                <col class="w-[25%]">
                                <col class="w-12">
                            </colgroup>
                            <thead class="border-b border-ui-border bg-surface-muted text-xs font-semibold uppercase tracking-wide text-text-muted">
                                <tr>
                                    <th class="px-4 py-3"><span class="sr-only">Select</span></th>
                                    @foreach($residenceSortableColumns as $column => $meta)
                                        <th class="px-4 py-1.5 tracking-normal" data-sort-th="{{ $column }}" aria-sort="none">
                                            <span class="inline-flex items-stretch gap-1">
                                                <button type="button" class="flex cursor-pointer items-center bg-transparent p-0 text-left transition hover:text-brand-primary focus-visible:text-brand-primary focus-visible:outline-none active:text-brand-primary" data-sort-toggle data-sort-key="{{ $column }}" data-sort-type="{{ $meta['type'] }}" aria-label="Sort by {{ $meta['label'] }}">{{ $meta['label'] }}</button>
                                                <span class="inline-flex flex-col" role="group" aria-label="Sort by {{ $meta['label'] }}">
                                                    <button type="button" class="flex h-7 w-8 shrink-0 items-center justify-center text-text-muted/40 transition hover:text-brand-primary focus-visible:text-brand-primary focus-visible:outline-none sm:h-3.5 sm:w-4" data-sort-btn data-sort-key="{{ $column }}" data-sort-type="{{ $meta['type'] }}" data-sort-dir="asc" aria-label="Sort {{ $meta['label'] }} ascending">
                                                        <x-ui.icon name="chevron-up" size="size-2.5" class="pointer-events-none" />
                                                    </button>
                                                    <button type="button" class="flex h-7 w-8 shrink-0 items-center justify-center text-text-muted/40 transition hover:text-brand-primary focus-visible:text-brand-primary focus-visible:outline-none sm:h-3.5 sm:w-4" data-sort-btn data-sort-key="{{ $column }}" data-sort-type="{{ $meta['type'] }}" data-sort-dir="desc" aria-label="Sort {{ $meta['label'] }} descending">
                                                        <x-ui.icon name="chevron-down" size="size-2.5" class="pointer-events-none" />
                                                    </button>
                                                </span>
                                            </span>
                                        </th>
                                    @endforeach
                                    <th class="px-2 py-3 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-ui-border">
                                @if($residenceChecks->isEmpty())
                                    <tr>
                                        <td colspan="5" class="px-4 py-4 text-center">
                                            <p class="text-sm text-text-muted">No Residence Check saved yet for {{ $personName }}.</p>
                                            <p class="mt-0.5 text-xs text-text-muted">Add Residence Check to create the first report for this person.</p>
                                        </td>
                                    </tr>
                                @endif
                                @foreach($residenceChecks as $check)
                                    <tr class="transition hover:bg-surface-muted has-[[data-residence-check-select]:checked]:bg-brand-soft/40"
                                        data-sort-ci_date="{{ $check->ci_date?->format('Y-m-d') ?? '' }}"
                                        data-sort-location="{{ strtolower($check->location ?? '') }}"
                                        data-sort-ci="{{ strtolower($check->investigator?->full_name ?? '') }}"
                                    >
                                        <td class="px-4 py-3.5 align-middle"><input type="checkbox" class="size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary" data-residence-check-select value="{{ $check->id }}" aria-label="Select this Residence Check"></td>
                                        <td class="px-4 py-3.5 align-middle text-sm text-text-muted">{{ $check->ci_date?->format('M j, Y') ?? '—' }}</td>
                                        <td class="px-4 py-3.5 align-middle"><span class="line-clamp-2 text-sm text-text-main" title="{{ $check->location }}">{{ $check->location ?: '—' }}</span></td>
                                        <td class="px-4 py-3.5 align-middle text-sm text-text-muted">{{ $check->investigator?->full_name ?? '—' }}</td>
                                        <td class="px-2 py-3.5 align-middle text-center">
                                            <x-ui.context-menu label="Residence Check actions">
                                                <x-slot:trigger>
                                                    <span class="ui-action-icon-button ui-action-icon-button-neutral group-open:border-brand-primary group-open:bg-brand-soft group-open:text-brand-primary" title="Actions"><x-ui.icon name="more-vertical" size="size-4" /></span>
                                                </x-slot:trigger>
                                                @if($check->photos_count > 0)
                                                    <button type="button" role="menuitem" data-modal-open="residence-check-photos-{{ $check->id }}" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="eye" size="size-4" class="text-text-muted" />View Photos</button>
                                                @endif
                                                <a href="{{ route('client-folders.residence-checks.edit', [$clientFolder, $check] + $personParams) }}" role="menuitem" data-modal-open="check-report-dialog" data-check-report-url="{{ route('client-folders.residence-checks.edit', [$clientFolder, $check] + $personParams) }}" data-check-report-title="Residence Check" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="edit" size="size-4" class="text-text-muted" />Edit</a>
                                                <button type="button" role="menuitem" data-check-row-print data-check-kind="residence" data-check-id="{{ $check->id }}" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="printer" size="size-4" class="text-text-muted" />Print</button>
                                                <button type="button" role="menuitem" data-check-row-pdf-submit data-check-kind="residence" data-check-id="{{ $check->id }}" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="report" size="size-4" class="text-danger" />Download PDF</button>
                                                <button type="button" role="menuitem" data-check-row-docx-submit data-check-kind="residence" data-check-id="{{ $check->id }}" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="report" size="size-4" class="text-brand-primary" />Download Word</button>
                                                <div class="my-1 border-t border-ui-border"></div>
                                                <button type="button" role="menuitem" data-modal-open="delete-residence-check-{{ $check->id }}" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold text-danger hover:bg-danger-soft"><x-ui.icon name="trash" size="size-4" />Delete</button>
                                            </x-ui.context-menu>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
            </section>

            {{-- Business Checks --}}
            <section class="ui-panel p-4 sm:p-5" aria-labelledby="business-checks-title">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <span class="grid size-8 shrink-0 place-items-center rounded-control bg-success-soft text-success"><x-ui.icon name="building" size="size-4" /></span>
                        <h2 id="business-checks-title" class="ui-section-title">Business Checks</h2>
                    </div>
                    <a href="{{ route('client-folders.business-checks.create', [$clientFolder] + $personParams) }}" data-modal-open="check-report-dialog" data-check-report-url="{{ route('client-folders.business-checks.create', [$clientFolder] + $personParams) }}" data-check-report-title="Business Checks" class="ui-button-primary-compact"><x-ui.icon name="plus" size="size-3.5" />Add Business Check</a>
                </div>

                <div class="overflow-x-auto rounded-card border border-ui-border">
                    <table class="w-full min-w-[600px] table-fixed divide-y divide-ui-border text-left text-sm" data-check-sort-table>
                            <colgroup>
                                <col class="w-10">
                                <col class="w-[16%]">
                                <col class="w-[30%]">
                                <col class="w-[32%]">
                                <col class="w-[18%]">
                                <col class="w-12">
                            </colgroup>
                            <thead class="border-b border-ui-border bg-surface-muted text-xs font-semibold uppercase tracking-wide text-text-muted">
                                <tr>
                                    <th class="px-4 py-3"><span class="sr-only">Select</span></th>
                                    @foreach($sortableColumns as $column => $meta)
                                        <th class="px-4 py-1.5 tracking-normal" data-sort-th="{{ $column }}" aria-sort="none">
                                            <span class="inline-flex items-stretch gap-1">
                                                <button type="button" class="flex cursor-pointer items-center bg-transparent p-0 text-left transition hover:text-brand-primary focus-visible:text-brand-primary focus-visible:outline-none active:text-brand-primary" data-sort-toggle data-sort-key="{{ $column }}" data-sort-type="{{ $meta['type'] }}" aria-label="Sort by {{ $meta['label'] }}">{{ $meta['label'] }}</button>
                                                <span class="inline-flex flex-col" role="group" aria-label="Sort by {{ $meta['label'] }}">
                                                    <button type="button" class="flex h-7 w-8 shrink-0 items-center justify-center text-text-muted/40 transition hover:text-brand-primary focus-visible:text-brand-primary focus-visible:outline-none sm:h-3.5 sm:w-4" data-sort-btn data-sort-key="{{ $column }}" data-sort-type="{{ $meta['type'] }}" data-sort-dir="asc" aria-label="Sort {{ $meta['label'] }} ascending">
                                                        <x-ui.icon name="chevron-up" size="size-2.5" class="pointer-events-none" />
                                                    </button>
                                                    <button type="button" class="flex h-7 w-8 shrink-0 items-center justify-center text-text-muted/40 transition hover:text-brand-primary focus-visible:text-brand-primary focus-visible:outline-none sm:h-3.5 sm:w-4" data-sort-btn data-sort-key="{{ $column }}" data-sort-type="{{ $meta['type'] }}" data-sort-dir="desc" aria-label="Sort {{ $meta['label'] }} descending">
                                                        <x-ui.icon name="chevron-down" size="size-2.5" class="pointer-events-none" />
                                                    </button>
                                                </span>
                                            </span>
                                        </th>
                                    @endforeach
                                    <th class="px-2 py-3 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-ui-border">
                                @if($businessChecks->isEmpty())
                                    <tr>
                                        <td colspan="6" class="px-4 py-4 text-center">
                                            <p class="text-sm text-text-muted">No Business Checks saved yet.</p>
                                            <p class="mt-0.5 text-xs text-text-muted">Add Business Check to create the first report for this person.</p>
                                        </td>
                                    </tr>
                                @endif
                                @foreach($businessChecks as $check)
                                    <tr class="transition hover:bg-surface-muted has-[[data-business-check-select]:checked]:bg-brand-soft/40"
                                        data-sort-ci_date="{{ $check->ci_date?->format('Y-m-d') ?? '' }}"
                                        data-sort-business_subject="{{ strtolower($check->incomeSource?->displayName() ?? '') }}"
                                        data-sort-location="{{ strtolower($check->location ?? '') }}"
                                        data-sort-ci="{{ strtolower($check->investigator?->full_name ?? '') }}"
                                    >
                                        <td class="px-4 py-3.5 align-middle"><input type="checkbox" class="size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary" data-business-check-select value="{{ $check->id }}" aria-label="Select this Business Check"></td>
                                        <td class="px-4 py-3.5 align-middle text-sm text-text-muted">{{ $check->ci_date?->format('M j, Y') ?? '—' }}</td>
                                        <td class="px-4 py-3.5 align-middle"><span class="inline-flex items-center rounded-full bg-success-soft px-2.5 py-1 text-xs font-semibold text-success">{{ $check->incomeSource?->displayName() ?? '—' }}</span></td>
                                        <td class="px-4 py-3.5 align-middle"><span class="line-clamp-2 text-sm text-text-main" title="{{ $check->location }}">{{ $check->location ?: '—' }}</span></td>
                                        <td class="px-4 py-3.5 align-middle text-sm text-text-muted">{{ $check->investigator?->full_name ?? '—' }}</td>
                                        <td class="px-2 py-3.5 align-middle text-center">
                                            <x-ui.context-menu label="Business Check actions">
                                                <x-slot:trigger>
                                                    <span class="ui-action-icon-button ui-action-icon-button-neutral group-open:border-brand-primary group-open:bg-brand-soft group-open:text-brand-primary" title="Actions"><x-ui.icon name="more-vertical" size="size-4" /></span>
                                                </x-slot:trigger>
                                                @if(($check->business_photos_count + $check->competitor_photos_count) > 0)
                                                    <button type="button" role="menuitem" data-modal-open="business-check-photos-{{ $check->id }}" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="eye" size="size-4" class="text-text-muted" />View Photos</button>
                                                @endif
                                                <a href="{{ route('client-folders.business-checks.edit', [$clientFolder, $check] + $personParams) }}" role="menuitem" data-modal-open="check-report-dialog" data-check-report-url="{{ route('client-folders.business-checks.edit', [$clientFolder, $check] + $personParams) }}" data-check-report-title="Business Checks" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="edit" size="size-4" class="text-text-muted" />Edit</a>
                                                <button type="button" role="menuitem" data-check-row-print data-check-kind="business" data-check-id="{{ $check->id }}" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="printer" size="size-4" class="text-text-muted" />Print</button>
                                                <button type="button" role="menuitem" data-check-row-pdf-submit data-check-kind="business" data-check-id="{{ $check->id }}" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="report" size="size-4" class="text-danger" />Download PDF</button>
                                                <button type="button" role="menuitem" data-check-row-docx-submit data-check-kind="business" data-check-id="{{ $check->id }}" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="report" size="size-4" class="text-brand-primary" />Download Word</button>
                                                <div class="my-1 border-t border-ui-border"></div>
                                                <button type="button" role="menuitem" data-modal-open="delete-business-check-{{ $check->id }}" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold text-danger hover:bg-danger-soft"><x-ui.icon name="trash" size="size-4" />Delete</button>
                                            </x-ui.context-menu>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
            </section>
        </div>

        <aside class="h-fit rounded-panel border border-ui-border bg-surface-muted p-4 shadow-card xl:sticky xl:top-20" aria-labelledby="check-summary-title">
            <div class="mb-2.5 flex items-center gap-2">
                <span class="text-brand-primary"><x-ui.icon name="report" size="size-4" /></span>
                <h2 id="check-summary-title" class="text-sm font-semibold text-brand-sidebar">Report Summary</h2>
            </div>

            <p class="text-3xl font-bold text-brand-primary" data-check-selected-summary-count>0</p>
            <p class="text-sm font-semibold text-text-main">Selected Reports</p>
            <p class="text-xs text-text-muted">of {{ $totalChecks }} total</p>

            <div class="mt-3 space-y-1.5 border-t border-ui-border pt-3 text-sm">
                <p class="flex items-center gap-1.5 text-text-muted"><x-ui.icon name="home" size="size-3.5" class="text-brand-primary" />{{ $residenceChecks->count() }} Residence Report{{ $residenceChecks->count() === 1 ? '' : 's' }}</p>
                <p class="flex items-center gap-1.5 text-text-muted"><x-ui.icon name="building" size="size-3.5" class="text-success" />{{ $businessChecks->count() }} Business Report{{ $businessChecks->count() === 1 ? '' : 's' }}</p>
            </div>
        </aside>
    </div>

    @foreach($residenceChecks as $check)
        @if($check->photos_count > 0)
            <x-ui.modal id="residence-check-photos-{{ $check->id }}" title="Residence Check Photos" :description="$check->ci_date?->format('M j, Y')" size="max-w-2xl">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach($check->photos as $photo)
                        <a href="{{ route('client-folders.residence-checks.photo', [$clientFolder, $check, $photo]) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-control border border-ui-border">
                            <img src="{{ route('client-folders.residence-checks.photo', [$clientFolder, $check, $photo, 'thumbnail' => 1]) }}" alt="{{ $photo->caption ?? 'Residence photo' }}" class="h-28 w-full object-cover">
                        </a>
                    @endforeach
                </div>
            </x-ui.modal>
        @endif
        <x-ui.confirmation-dialog id="delete-residence-check-{{ $check->id }}" title="Delete this Residence Check?" :action="route('client-folders.residence-checks.destroy', [$clientFolder, $check])" method="DELETE" confirm-label="Delete Permanently" destructive>
            <p class="text-sm text-text-muted">Are you sure you want to permanently delete this Residence Check and its photos? This action cannot be undone.</p>
        </x-ui.confirmation-dialog>
    @endforeach

    @foreach($businessChecks as $check)
        @if(($check->business_photos_count + $check->competitor_photos_count) > 0)
            <x-ui.modal id="business-check-photos-{{ $check->id }}" title="Business Check Photos" :description="$check->incomeSource?->displayName()" size="max-w-2xl">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach($check->photos as $photo)
                        <a href="{{ route('client-folders.business-checks.photo', [$clientFolder, $check, $photo]) }}" target="_blank" rel="noopener" class="relative block overflow-hidden rounded-control border border-ui-border">
                            <img src="{{ route('client-folders.business-checks.photo', [$clientFolder, $check, $photo, 'thumbnail' => 1]) }}" alt="{{ $photo->caption ?? 'Business photo' }}" class="h-28 w-full object-cover">
                            @if($photo->category?->value === 'competitor')<span class="absolute bottom-1 left-1 rounded bg-brand-sidebar/80 px-1.5 py-0.5 text-[0.65rem] font-semibold text-white">Competitor</span>@endif
                        </a>
                    @endforeach
                </div>
            </x-ui.modal>
        @endif
        {{-- $personParams (computed at the top of this file) carries the active person forward —
             without it, deleting a Co-Maker's own Business Check would submit with no
             person/co_maker_id in the request at all, ActivePersonResolver::resolveFromQuery()
             would resolve null, and assertOwnedBy() would 404 a check that genuinely belongs to
             this exact Co-Maker (see ActivePersonResolver::assertOwnedBy()). --}}
        <x-ui.confirmation-dialog id="delete-business-check-{{ $check->id }}" title="Delete this Business Check?" :action="route('client-folders.business-checks.destroy', [$clientFolder, $check] + $personParams)" method="DELETE" confirm-label="Delete Permanently" destructive>
            <p class="text-sm text-text-muted">Are you sure you want to permanently delete this Business Check and its photos? This action cannot be undone.</p>
        </x-ui.confirmation-dialog>
    @endforeach

    <x-ui.check-report-modal />

    <form id="check-batch-print-form" method="POST" action="{{ route('client-folders.residence-business-checks.batch-print', $clientFolder) }}" target="_blank" hidden>
        @csrf<input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
    </form>
    <form id="check-batch-export-pdf-form" method="POST" action="{{ route('client-folders.residence-business-checks.batch-export-pdf', $clientFolder) }}" hidden>
        @csrf<input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
    </form>
    <form id="check-batch-export-docx-form" method="POST" action="{{ route('client-folders.residence-business-checks.batch-export-docx', $clientFolder) }}" hidden>
        @csrf<input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
    </form>
@endsection
