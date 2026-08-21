@extends('layouts.app')

@section('title', $clientFolder->display_name)

@section('content')
    @php
        // Active-person navigation: the query string is the source of truth (no session state),
        // so switching stays a plain link/redirect within this same Client Folder page — nothing
        // here creates another folder or record. The controller resolves the identical value
        // (ActivePersonResolver::resolveFromQuery) to scope Folder Contents' own counts/state
        // before this view ever renders; this recomputes it only for display/link-building.
        $coMakers = $clientFolder->coMakers;
        $activeCoMaker = request()->query('person') === 'co-maker'
            ? $coMakers->firstWhere('id', (int) request()->query('co_maker_id'))
            : null;
        $viewingLabel = $activeCoMaker ? 'Co-Maker — '.mb_strtoupper($activeCoMaker->full_name) : 'Applicant';
        $personSwitchUrl = fn (?App\Models\CoMaker $coMaker = null) => $coMaker
            ? route('client-folders.show', $clientFolder).'?person=co-maker&co_maker_id='.$coMaker->id
            : route('client-folders.show', $clientFolder);
        $personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activeCoMaker);

        $moduleRoutes = [
            'client-information' => route('client-folders.client-information.edit', $clientFolder),
            'activities' => route('client-folders.activities.index', [$clientFolder] + $personParams),
            'cibi-report' => route('client-folders.cibi-report.edit', [$clientFolder] + $personParams),
            'income-sources' => route('client-folders.income-sources.manage', [$clientFolder] + $personParams),
            'residence-business' => route('client-folders.residence-business.edit', [$clientFolder] + $personParams),
            'generated-reports' => route('client-folders.generated-reports.index', [$clientFolder] + $personParams),
            'media' => route('client-folders.media.index', [$clientFolder] + $personParams),
        ];
        $moduleHref = fn (array $module) => $moduleRoutes[$module['key']] ?? route('client-folders.modules.show', [$clientFolder, $module['key']]);
        $displayTimezone = config('cims.display_timezone');
        $countBadge = fn (int $count, string $singular) => $count > 0 ? $count.' '.\Illuminate\Support\Str::plural($singular, $count) : null;
        $moduleBadges = [
            'income-sources' => $countBadge($clientFolder->income_sources_count, 'Business'),
            'activities' => $countBadge($clientFolder->activities_count, 'Activity'),
            'media' => $countBadge($clientFolder->media_references_count, 'File'),
            'generated-reports' => $countBadge($clientFolder->generated_reports_count, 'Report'),
            'attachments' => $countBadge($clientFolder->attachments_count, 'File'),
            'google-drive' => $countBadge($clientFolder->drive_references_count, 'Reference'),
            'telegram-history' => $countBadge($clientFolder->telegram_messages_count, 'Message'),
        ];
        $moduleOpenLabels = ['cibi-report' => 'Open'];
        $cibiComplete = $clientFolder->cibiReport?->state === \App\Enums\RecordState::Complete;
        $canManageCoMakers = auth()->user()->can('update', $clientFolder);
    @endphp

    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name]]" />

    <div class="space-y-5">
        <x-ui.client-header
            :name="$clientFolder->display_name"
            :folder-number="$clientFolder->folder_number"
            :status="$clientFolder->status"
            :progress="$progress['percentage']"
        >
            @if($canManageCoMakers)
                <x-slot:personActions>
                    <button type="button" class="ui-button-primary-compact" data-modal-open="co-maker-dialog" data-co-maker-add-trigger><x-ui.icon name="plus" size="size-3.5" />Add Co-Maker</button>
                    <p class="max-w-xs text-xs text-text-muted lg:text-right">Add a Co-Maker to maintain a separate set of CI/BI, business, activity, and supporting records under this Client Folder.</p>
                </x-slot:personActions>
            @endif
        </x-ui.client-header>

        @if($coMakers->isNotEmpty())
            <section class="ui-panel p-3.5 sm:p-4" aria-labelledby="person-switch-title">
                <h2 id="person-switch-title" class="sr-only">Switch active person</h2>
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex flex-wrap items-center gap-2 overflow-x-auto">
                        <a
                            href="{{ $personSwitchUrl() }}"
                            class="flex cursor-pointer items-center gap-2.5 rounded-control px-3 py-2 transition {{ $activeCoMaker ? 'text-text-muted hover:bg-surface-muted hover:text-brand-sidebar' : 'bg-brand-soft text-brand-primary' }}"
                        >
                            <span class="grid size-8 shrink-0 place-items-center rounded-full {{ $activeCoMaker ? 'bg-surface-muted text-text-muted' : 'bg-white text-brand-primary' }}"><x-ui.icon name="user" size="size-4" /></span>
                            <span class="text-left">
                                <span class="block text-xs font-semibold uppercase tracking-wide {{ $activeCoMaker ? 'text-text-muted' : 'text-brand-primary' }}">Applicant @unless($activeCoMaker)(<span class="text-success">Active</span>)@endunless</span>
                                <span class="block text-sm font-bold text-text-main">{{ $clientFolder->display_name }}</span>
                            </span>
                        </a>
                        @foreach($coMakers as $coMaker)
                            @php($coMakerIsActive = $activeCoMaker?->id === $coMaker->id)
                            <div class="flex items-center rounded-control transition {{ $coMakerIsActive ? 'bg-brand-soft' : 'hover:bg-surface-muted' }}">
                                <a
                                    href="{{ $personSwitchUrl($coMaker) }}"
                                    data-co-maker-tab="{{ $coMaker->id }}"
                                    class="flex cursor-pointer items-center gap-2.5 px-3 py-2 {{ $coMakerIsActive ? 'text-brand-primary' : 'text-text-muted hover:text-brand-sidebar' }}"
                                >
                                    <span class="grid size-8 shrink-0 place-items-center rounded-full {{ $coMakerIsActive ? 'bg-white text-brand-primary' : 'bg-folder-soft text-progress' }}"><x-ui.icon name="user" size="size-4" /></span>
                                    <span class="text-left">
                                        <span class="block text-xs font-semibold uppercase tracking-wide {{ $coMakerIsActive ? 'text-brand-primary' : 'text-text-muted' }}">Co-Maker{{ $coMakers->count() > 1 ? ' '.$loop->iteration : '' }} @if($coMakerIsActive)(<span class="text-success">Active</span>)@endif</span>
                                        <span class="block text-sm font-bold uppercase text-text-main" data-co-maker-tab-name>{{ $coMaker->full_name }}</span>
                                    </span>
                                </a>
                                @if($canManageCoMakers)
                                    <button
                                        type="button"
                                        class="ui-dots-trigger mr-1"
                                        aria-haspopup="menu"
                                        aria-expanded="false"
                                        aria-label="Co-Maker{{ $coMakers->count() > 1 ? ' '.$loop->iteration : '' }} actions"
                                        data-co-maker-menu-trigger
                                        data-co-maker-id="{{ $coMaker->id }}"
                                        data-co-maker-full-name="{{ $coMaker->full_name }}"
                                        data-co-maker-first-name="{{ $coMaker->first_name }}"
                                        data-co-maker-middle-name="{{ $coMaker->middle_name }}"
                                        data-co-maker-last-name="{{ $coMaker->last_name }}"
                                        data-co-maker-suffix="{{ $coMaker->suffix }}"
                                        data-co-maker-destroy-base-url="{{ route('client-folders.co-maker.store', $clientFolder) }}"
                                    ><x-ui.icon name="more" size="size-4 rotate-90" /></button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-3">
                        @if($coMakers->isNotEmpty())
                            <span class="text-xs font-medium text-text-muted">Current View: <span class="rounded-full bg-brand-soft px-2 py-1 font-bold text-brand-primary">{{ $viewingLabel }}</span></span>
                            <x-ui.context-menu label="Switch Person">
                                <x-slot:trigger>
                                    <span class="ui-button-secondary-compact"><x-ui.icon name="users" size="size-3.5" />Switch Person<x-ui.icon name="chevron-down" size="size-3.5" /></span>
                                </x-slot:trigger>
                                <a href="{{ $personSwitchUrl() }}" role="menuitem" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary">Applicant — {{ $clientFolder->display_name }}</a>
                                @foreach($coMakers as $coMaker)
                                    <a href="{{ $personSwitchUrl($coMaker) }}" role="menuitem" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary">Co-Maker{{ $coMakers->count() > 1 ? ' '.$loop->iteration : '' }} — {{ $coMaker->full_name }}</a>
                                @endforeach
                            </x-ui.context-menu>
                        @endif
                    </div>
                </div>
            </section>
        @endif

        <section class="ui-panel p-4 sm:p-5" aria-labelledby="folder-modules-title">
            <div class="mb-4 flex flex-wrap items-center gap-2.5">
                <span class="text-brand-primary"><x-ui.icon name="chart" size="size-5" /></span>
                <h2 id="folder-modules-title" class="text-base font-semibold text-brand-sidebar">Folder Contents @if($coMakers->isNotEmpty())<span class="font-normal text-text-muted">(Viewing: {{ $viewingLabel }})</span>@endif</h2>
            </div>
            <nav class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-label="Client folder modules">
                @foreach($modules as $module)
                    @continue($module['key'] === 'client-information')
                    <x-ui.module-card
                        :id="match($module['key']) { 'cibi-report' => 'open-cibi-report', 'income-sources' => 'open-business-report', default => null }"
                        :title="$module['title']"
                        :icon="$module['icon']"
                        :state="$module['state']"
                        :badge="$moduleBadges[$module['key']] ?? null"
                        :description="$module['key'] === 'income-sources' ? null : $module['description']"
                        :href="$moduleHref($module)"
                        :modal-id="$module['key'] === 'cibi-report' ? 'cibi-report-dialog' : null"
                        :modal-url="$module['key'] === 'cibi-report' ? $moduleHref($module) : null"
                        :updated-at="$module['updatedAt'] ? Illuminate\Support\Carbon::parse($module['updatedAt'])->timezone($displayTimezone)->format('M j, Y') : null"
                        :open-label="$moduleOpenLabels[$module['key']] ?? 'Open'"
                        :open-icon="$module['key'] === 'cibi-report' && $cibiComplete ? 'edit' : 'open'"
                    >
                        @if($module['key'] === 'cibi-report' && $cibiComplete)
                            <x-slot:footer>
                                <a href="{{ route('client-folders.generated-reports.preview', [$clientFolder, 'report_type' => 'cibi'] + $personParams) }}" target="_blank" rel="noopener" class="ui-button-secondary-compact"><x-ui.icon name="eye" size="size-3.5" />Preview</a>
                                <x-ui.context-menu label="Download CI / BI report">
                                    <x-slot:trigger>
                                        <span class="ui-button-secondary-compact"><x-ui.icon name="download" size="size-3.5" />Download<x-ui.icon name="chevron-down" size="size-3.5" /></span>
                                    </x-slot:trigger>
                                    <button type="submit" form="dashboard-cibi-export-pdf-form" role="menuitem" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="report" size="size-4" />Download PDF</button>
                                    <button type="submit" form="dashboard-cibi-export-excel-form" role="menuitem" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="spreadsheet" size="size-4" />Download Excel</button>
                                </x-ui.context-menu>
                            </x-slot:footer>
                        @endif
                    </x-ui.module-card>
                @endforeach
            </nav>
        </section>
    </div>

    @if($cibiComplete)
        <form id="dashboard-cibi-export-pdf-form" method="POST" action="{{ route('client-folders.cibi-report.export-pdf', $clientFolder) }}" target="_blank" hidden>
            @csrf
            <input type="hidden" name="co_maker_id" value="{{ $activeCoMaker?->id }}">
        </form>
        <form id="dashboard-cibi-export-excel-form" method="POST" action="{{ route('client-folders.cibi-report.export-excel', $clientFolder) }}" hidden>
            @csrf
            <input type="hidden" name="co_maker_id" value="{{ $activeCoMaker?->id }}">
        </form>
    @endif

    <x-ui.cibi-report-modal />
    @can('update', $clientFolder)
        @include('client-folders._co-maker-modal')
        @include('client-folders._co-maker-remove-modal')
        {{-- Rendered once at the end of the page (not inside the tabs container) and positioned
             via fixed coordinates from JS, so it can never be clipped by the tabs row's
             horizontal-scroll overflow. Edit/Remove data attributes are populated per co-maker
             at open time — see the co-maker action menu script in app.js. --}}
        <div
            id="co-maker-action-menu"
            class="fixed z-50 min-w-48 rounded-card border border-ui-border bg-surface p-1.5 shadow-float"
            role="menu"
            data-co-maker-action-menu
            hidden
        >
            <button type="button" role="menuitem" class="flex min-h-9 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold text-text-main hover:bg-surface-muted" data-modal-open="co-maker-dialog" data-co-maker-edit-trigger><x-ui.icon name="edit" size="size-3.5" />Edit Co-Maker</button>
            <button type="button" role="menuitem" class="flex min-h-9 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold text-danger hover:bg-danger-soft" data-co-maker-remove-trigger><x-ui.icon name="trash" size="size-3.5" />Remove Co-Maker</button>
        </div>
    @endcan
@endsection
