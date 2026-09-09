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
        $personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activeCoMaker);

        $moduleRoutes = [
            'client-information' => route('client-folders.client-information.edit', $clientFolder),
            'activities' => route('client-folders.activities.index', [$clientFolder] + $personParams),
            'cibi-report' => route('client-folders.cibi-report.edit', [$clientFolder] + $personParams),
            'income-sources' => route('client-folders.income-sources.manage', [$clientFolder] + $personParams),
            'residence-business' => route('client-folders.residence-business.edit', [$clientFolder] + $personParams),
        ];
        $moduleHref = fn (array $module) => $moduleRoutes[$module['key']] ?? route('client-folders.modules.show', [$clientFolder, $module['key']]);
        $displayTimezone = config('cims.display_timezone');
        $countBadge = fn (int $count, string $singular) => $count > 0 ? $count.' '.\Illuminate\Support\Str::plural($singular, $count) : null;
        $moduleBadges = [
            'income-sources' => $countBadge($clientFolder->income_sources_count, 'Business'),
            'activities' => $countBadge($clientFolder->activities_count, 'Activity'),
        ];
        // Resolved independently per active person: $clientFolder->cibiReport is already scoped
        // to the current Applicant/Co-Maker by ClientFolderOverview::for() (co_maker_id filter on
        // the eager load), so this never mixes one person's CI/BI state into another's button.
        $cibiHasReport = $clientFolder->cibiReport !== null;
        $moduleOpenLabels = ['cibi-report' => $cibiHasReport ? 'Open' : 'Add'];
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
                    <button type="button" id="co-maker-add-trigger" class="ui-button-primary-compact" data-modal-open="co-maker-dialog" data-co-maker-add-trigger><x-ui.icon name="plus" size="size-3.5" />Add Co-Maker</button>
                    <p class="max-w-xs text-xs text-text-muted lg:text-right">Add a Co-Maker to maintain a separate set of CI/BI, business, activity, and supporting records under this Client Folder.</p>
                </x-slot:personActions>
            @endif
        </x-ui.client-header>

        <div data-person-switch-region>
            @include('client-folders.partials.person-switch', ['clientFolder' => $clientFolder, 'coMakers' => $coMakers, 'activeCoMaker' => $activeCoMaker, 'canManageCoMakers' => $canManageCoMakers])
        </div>

        <div class="client-folder-contents-layout grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(15rem,23%)]">
            <section class="ui-panel p-4 sm:p-5" aria-labelledby="folder-modules-title">
                <div class="mb-4 flex flex-wrap items-center gap-2.5">
                    <span class="text-brand-primary"><x-ui.icon name="chart" size="size-5" /></span>
                    <h2 id="folder-modules-title" class="text-base font-semibold text-brand-sidebar">Folder Contents @if($coMakers->isNotEmpty())<span class="font-normal text-text-muted">(Viewing: {{ $viewingLabel }})</span>@endif</h2>
                </div>
                <nav class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-label="Client folder modules">
                    @foreach($modules as $module)
                        @continue($module['key'] === 'client-information')
                        @if($module['key'] === 'cibi-report')
                            @include('client-folders.partials.cibi-module-card', ['clientFolder' => $clientFolder, 'cibiReport' => $report, 'activePerson' => $activeCoMaker, 'displayTimezone' => $displayTimezone])
                            @continue
                        @endif
                        <x-ui.module-card
                            :id="$module['key'] === 'income-sources' ? 'open-business-report' : null"
                            :title="$module['title']"
                            :icon="$module['icon']"
                            :state="$module['state']"
                            :badge="$moduleBadges[$module['key']] ?? null"
                            :description="$module['key'] === 'income-sources' ? null : $module['description']"
                            :href="$moduleHref($module)"
                            :updated-at="$module['updatedAt'] ? Illuminate\Support\Carbon::parse($module['updatedAt'])->timezone($displayTimezone)->format('M j, Y') : null"
                            :open-label="$moduleOpenLabels[$module['key']] ?? 'Open'"
                            open-icon="open"
                        />
                    @endforeach
                </nav>
            </section>

            <aside class="ui-panel min-w-0 p-5" aria-labelledby="recent-activity-title">
                <div class="flex items-center gap-2 text-brand-primary">
                    <x-ui.icon name="clock" size="size-5" />
                    <h2 id="recent-activity-title" class="text-base font-bold text-brand-sidebar">Recent Activity</h2>
                </div>

                <div data-recent-activity-body>
                    @include('client-folders.partials.recent-activity-body', ['recentPersonActivity' => $recentPersonActivity, 'coMakers' => $coMakers, 'viewingLabel' => $viewingLabel, 'displayTimezone' => $displayTimezone])
                </div>
                <div class="mt-5 border-t border-ui-border pt-4">
                    <button type="button" class="w-full text-center text-sm font-bold text-brand-primary hover:underline" data-modal-open="recent-activity-dialog">View All</button>
                </div>
            </aside>
        </div>
    </div>

    @if($cibiComplete)
        <form id="dashboard-cibi-export-excel-form" method="POST" action="{{ route('client-folders.cibi-report.export-excel', $clientFolder) }}" hidden>
            @csrf
            <input type="hidden" name="co_maker_id" value="{{ $activeCoMaker?->id }}">
        </form>
    @endif

    <x-ui.cibi-report-modal />
    <x-ui.recent-activity-modal id="recent-activity-dialog" :activities="$recentPersonActivity" />
    @if($cibiHasReport && auth()->user()->can('reassignSignatory', $report))
        <x-ui.cibi-signatory-reassignment-modal id="cibi-reassign-signatory-dialog" :client-folder="$clientFolder" :report="$report" :candidates="$reassignmentCandidates" />
    @endif
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
