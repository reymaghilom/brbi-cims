@extends('layouts.app')

@section('title', 'Residence & Business Report')

@section('content')
    @php($personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null))
    <x-ui.breadcrumb :items="[['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', [$clientFolder] + $personParams)], ['label' => 'Residence & Business Report']]" />
    <x-ui.page-header title="Residence & Business Report" eyebrow="Photo investigation record">
        <x-slot:description>Document residence and business checks for the active person using directly uploaded photos.</x-slot:description>
    </x-ui.page-header>

    @if(session('status'))<div class="mb-6 rounded-card border border-success/30 bg-success-soft p-4 text-sm font-semibold text-success" role="status">{{ session('status') }}</div>@endif

    <div class="mb-6 rounded-card border border-ui-border bg-surface p-3.5 shadow-card" data-check-batch-panel>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2.5 text-sm">
                <span class="font-semibold text-text-main">Combined output</span>
                <span class="text-text-muted">&bull;</span>
                <span class="font-medium text-text-muted" data-check-selected-count>0 selected</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="ui-button-primary-compact" data-check-print-selected disabled><x-ui.icon name="printer" size="size-3.5" />Print Selected</button>
                <x-ui.context-menu label="Download selected checks">
                    <x-slot:trigger>
                        <span class="ui-button-secondary-compact pointer-events-none opacity-55" data-check-download-selected-trigger aria-disabled="true" tabindex="-1"><x-ui.icon name="download" size="size-3.5" />Download Selected<x-ui.icon name="chevron-down" size="size-3.5" /></span>
                    </x-slot:trigger>
                    <button type="button" role="menuitem" data-check-batch-docx-submit class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="report" size="size-4" class="text-brand-primary" />Download Word</button>
                    <button type="button" role="menuitem" data-check-batch-pdf-submit class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="report" size="size-4" class="text-danger" />Download PDF</button>
                </x-ui.context-menu>
            </div>
        </div>
    </div>

    {{-- Residence Checks --}}
    <section class="ui-panel mb-6 p-4 sm:p-5" aria-labelledby="residence-checks-title">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <span class="grid size-8 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-primary"><x-ui.icon name="home" size="size-4" /></span>
                <h2 id="residence-checks-title" class="ui-section-title">Residence Checks</h2>
            </div>
            <a href="{{ route('client-folders.residence-checks.create', [$clientFolder] + $personParams) }}" data-modal-open="check-report-dialog" data-check-report-url="{{ route('client-folders.residence-checks.create', [$clientFolder] + $personParams) }}" class="ui-button-primary-compact"><x-ui.icon name="plus" size="size-3.5" />Add Residence Check</a>
        </div>

        @if($residenceChecks->isEmpty())
            <x-ui.empty-state title="No Residence Checks saved yet" description="Use Add Residence Check to start documenting the residence for this person." icon="home" />
        @else
            <div class="overflow-x-auto rounded-card border border-ui-border">
                <table class="w-full table-fixed divide-y divide-ui-border text-left text-sm">
                    <colgroup><col class="w-10"><col class="w-[16%]"><col class="w-[34%]"><col class="w-[16%]"><col class="w-[10%]"><col class="w-[24%]"></colgroup>
                    <thead class="border-b border-ui-border bg-surface-muted text-xs font-semibold uppercase tracking-wide text-text-muted">
                        <tr><th class="px-4 py-3"><span class="sr-only">Select</span></th><th class="px-4 py-3">CI Date</th><th class="px-4 py-3">Location</th><th class="px-4 py-3">Subject</th><th class="px-4 py-3">Photos</th><th class="px-4 py-3">Actions</th></tr>
                    </thead>
                    <tbody class="divide-y divide-ui-border">
                        @foreach($residenceChecks as $check)
                            <tr class="transition hover:bg-surface-muted">
                                <td class="px-4 py-3.5 align-middle"><input type="checkbox" class="size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary" data-residence-check-select value="{{ $check->id }}" aria-label="Select this Residence Check"></td>
                                <td class="px-4 py-3.5 align-middle text-sm text-text-muted">{{ $check->ci_date?->format('M j, Y') ?? '—' }}</td>
                                <td class="px-4 py-3.5 align-middle"><span class="line-clamp-2 text-sm text-text-main" title="{{ $check->location }}">{{ $check->location ?: '—' }}</span></td>
                                <td class="px-4 py-3.5 align-middle text-sm text-text-muted">Residence Check</td>
                                <td class="px-4 py-3.5 align-middle text-sm text-text-muted">{{ $check->photos_count }}</td>
                                <td class="px-4 py-3.5 align-middle">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <a href="{{ route('client-folders.residence-checks.edit', [$clientFolder, $check] + $personParams) }}" data-modal-open="check-report-dialog" data-check-report-url="{{ route('client-folders.residence-checks.edit', [$clientFolder, $check] + $personParams) }}" class="ui-action-icon-button ui-action-icon-button-neutral" title="Edit" aria-label="Edit Residence Check"><x-ui.icon name="edit" size="size-4" /></a>
                                        @if($check->photos_count > 0)<button type="button" data-modal-open="residence-check-photos-{{ $check->id }}" class="ui-action-icon-button ui-action-icon-button-neutral" title="View photos" aria-label="View photos"><x-ui.icon name="media" size="size-4" /></button>@endif
                                        <button type="button" data-modal-open="delete-residence-check-{{ $check->id }}" class="ui-action-icon-button ui-action-icon-button-danger" title="Delete" aria-label="Delete Residence Check"><x-ui.icon name="trash" size="size-4" /></button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Business Checks --}}
    <section class="ui-panel mb-6 p-4 sm:p-5" aria-labelledby="business-checks-title">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <span class="grid size-8 shrink-0 place-items-center rounded-control bg-success-soft text-success"><x-ui.icon name="building" size="size-4" /></span>
                <h2 id="business-checks-title" class="ui-section-title">Business Checks</h2>
            </div>
            <a href="{{ route('client-folders.business-checks.create', [$clientFolder] + $personParams) }}" data-modal-open="check-report-dialog" data-check-report-url="{{ route('client-folders.business-checks.create', [$clientFolder] + $personParams) }}" class="ui-button-primary-compact"><x-ui.icon name="plus" size="size-3.5" />Add Business Check</a>
        </div>

        @if($businessChecks->isEmpty())
            <x-ui.empty-state title="No Business Checks saved yet" description="Use Add Business Check to start documenting a saved business for this person." icon="building" />
        @else
            <div class="overflow-x-auto rounded-card border border-ui-border">
                <table class="w-full table-fixed divide-y divide-ui-border text-left text-sm">
                    <colgroup><col class="w-10"><col class="w-[12%]"><col class="w-[20%]"><col class="w-[22%]"><col class="w-[10%]"><col class="w-[8%]"><col class="w-[8%]"><col class="w-[20%]"></colgroup>
                    <thead class="border-b border-ui-border bg-surface-muted text-xs font-semibold uppercase tracking-wide text-text-muted">
                        <tr><th class="px-4 py-3"><span class="sr-only">Select</span></th><th class="px-4 py-3">CI Date</th><th class="px-4 py-3">Business Name</th><th class="px-4 py-3">Location</th><th class="px-4 py-3">Subject</th><th class="px-4 py-3">Photos</th><th class="px-4 py-3">Competitors</th><th class="px-4 py-3">Actions</th></tr>
                    </thead>
                    <tbody class="divide-y divide-ui-border">
                        @foreach($businessChecks as $check)
                            <tr class="transition hover:bg-surface-muted">
                                <td class="px-4 py-3.5 align-middle"><input type="checkbox" class="size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary" data-business-check-select value="{{ $check->id }}" aria-label="Select this Business Check"></td>
                                <td class="px-4 py-3.5 align-middle text-sm text-text-muted">{{ $check->ci_date?->format('M j, Y') ?? '—' }}</td>
                                <td class="px-4 py-3.5 align-middle"><span class="line-clamp-2 text-sm font-medium text-text-main">{{ $check->incomeSource?->displayName() ?? '—' }}</span></td>
                                <td class="px-4 py-3.5 align-middle"><span class="line-clamp-2 text-sm text-text-main" title="{{ $check->location }}">{{ $check->location ?: '—' }}</span></td>
                                <td class="px-4 py-3.5 align-middle text-sm text-text-muted">Business Check</td>
                                <td class="px-4 py-3.5 align-middle text-sm text-text-muted">{{ $check->business_photos_count }}</td>
                                <td class="px-4 py-3.5 align-middle text-sm text-text-muted">{{ $check->competitor_photos_count }}</td>
                                <td class="px-4 py-3.5 align-middle">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <a href="{{ route('client-folders.business-checks.edit', [$clientFolder, $check] + $personParams) }}" data-modal-open="check-report-dialog" data-check-report-url="{{ route('client-folders.business-checks.edit', [$clientFolder, $check] + $personParams) }}" class="ui-action-icon-button ui-action-icon-button-neutral" title="Edit" aria-label="Edit Business Check"><x-ui.icon name="edit" size="size-4" /></a>
                                        @if(($check->business_photos_count + $check->competitor_photos_count) > 0)<button type="button" data-modal-open="business-check-photos-{{ $check->id }}" class="ui-action-icon-button ui-action-icon-button-neutral" title="View photos" aria-label="View photos"><x-ui.icon name="media" size="size-4" /></button>@endif
                                        <button type="button" data-modal-open="delete-business-check-{{ $check->id }}" class="ui-action-icon-button ui-action-icon-button-danger" title="Delete" aria-label="Delete Business Check"><x-ui.icon name="trash" size="size-4" /></button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <x-ui.check-report-modal />

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
        <x-ui.confirmation-dialog id="delete-business-check-{{ $check->id }}" title="Delete this Business Check?" :action="route('client-folders.business-checks.destroy', [$clientFolder, $check])" method="DELETE" confirm-label="Delete Permanently" destructive>
            <p class="text-sm text-text-muted">Are you sure you want to permanently delete this Business Check and its photos? This action cannot be undone.</p>
        </x-ui.confirmation-dialog>
    @endforeach

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
