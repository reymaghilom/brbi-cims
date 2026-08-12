@extends('layouts.app')

@section('title', $clientFolder->display_name)

@section('content')
    @php
        $moduleRoutes = [
            'client-information' => route('client-folders.client-information.edit', $clientFolder),
            'activities' => route('client-folders.activities.index', $clientFolder),
            'cibi-report' => route('client-folders.cibi-report.edit', $clientFolder),
            'income-sources' => route('client-folders.income-sources.index', $clientFolder),
            'residence-business' => route('client-folders.residence-business.edit', $clientFolder),
            'generated-reports' => route('client-folders.generated-reports.index', $clientFolder),
            'media' => route('client-folders.media.index', $clientFolder),
        ];
        $moduleHref = fn (array $module) => $moduleRoutes[$module['key']] ?? route('client-folders.modules.show', [$clientFolder, $module['key']]);
        $displayTimezone = config('cims.display_timezone');
    @endphp

    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name]]" />

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,3fr)_minmax(17rem,1fr)]">
        <div class="contents xl:block xl:min-w-0 xl:space-y-5">
            <x-ui.client-header
                class="order-1"
                :name="$clientFolder->display_name"
                :folder-number="$clientFolder->folder_number"
                :status="$clientFolder->status"
            />

            <section class="order-3 ui-panel p-4 sm:p-5" aria-labelledby="folder-modules-title">
                <div class="mb-4 flex items-center gap-2.5">
                    <span class="text-brand-primary"><x-ui.icon name="chart" size="size-5" /></span>
                    <h2 id="folder-modules-title" class="text-base font-semibold text-brand-sidebar">Folder Contents</h2>
                </div>
                <nav class="grid gap-3 md:grid-cols-2" aria-label="Client folder modules">
                    @foreach($modules as $module)
                        @continue($module['key'] === 'client-information')
                        <x-ui.module-card
                            :id="$module['key'] === 'cibi-report' ? 'open-cibi-report' : null"
                            :title="$module['title']"
                            :icon="$module['icon']"
                            :state="$module['state']"
                            :description="$module['description']"
                            :href="$moduleHref($module)"
                            :modal-id="$module['key'] === 'cibi-report' ? 'cibi-report-dialog' : null"
                            :modal-url="$module['key'] === 'cibi-report' ? $moduleHref($module) : null"
                            :updated-at="$module['updatedAt'] ? Illuminate\Support\Carbon::parse($module['updatedAt'])->timezone($displayTimezone)->format('M j, Y') : null"
                        />
                    @endforeach
                </nav>
            </section>
        </div>

        <aside class="contents xl:sticky xl:top-20 xl:block xl:min-w-0 xl:space-y-4" aria-label="Client folder summary and recent activity">
            <section class="order-2 ui-panel overflow-hidden" aria-labelledby="folder-summary-title">
                <div class="flex items-center gap-2.5 px-4 pb-2 pt-4">
                    <span class="text-brand-primary"><x-ui.icon name="chart" size="size-5" /></span>
                    <h2 id="folder-summary-title" class="text-base font-semibold text-brand-sidebar">Folder Summary</h2>
                </div>

                <dl class="space-y-1 px-4 pb-3 text-sm">
                    <div class="flex items-start justify-between gap-4 rounded-control px-2 py-2"><dt class="flex items-center gap-2 font-medium text-text-muted"><x-ui.icon name="user" size="size-4" />Assigned Credit Investigator</dt><dd class="max-w-[55%] text-right font-semibold">{{ $clientFolder->assignedInvestigator->full_name }}</dd></div>
                    <div class="flex items-center justify-between gap-4 rounded-control px-2 py-2"><dt class="flex items-center gap-2 font-medium text-text-muted"><x-ui.icon name="calendar" size="size-4" />Created</dt><dd class="font-semibold">{{ $clientFolder->created_at->timezone($displayTimezone)->format('M j, Y') }}</dd></div>
                    <div class="flex items-center justify-between gap-4 rounded-control px-2 py-2"><dt class="flex items-center gap-2 font-medium text-text-muted"><x-ui.icon name="clock" size="size-4" />Last Updated</dt><dd class="font-semibold">{{ $clientFolder->updated_at->timezone($displayTimezone)->format('M j, Y') }}</dd></div>
                </dl>

                <div class="mx-4 grid grid-cols-4 gap-1 rounded-card bg-surface-muted p-2" aria-label="Folder record counts">
                    <div class="px-1 py-3 text-center"><span class="mx-auto grid size-8 place-items-center rounded-control bg-brand-soft text-brand-primary"><x-ui.icon name="activity" size="size-4" /></span><p class="mt-1.5 text-sm font-semibold tabular-nums">{{ $clientFolder->completed_required_activities_count }} / {{ $clientFolder->required_activities_count }}</p><p class="text-xs text-text-muted">Activities</p></div>
                    <div class="px-1 py-3 text-center"><span class="mx-auto grid size-8 place-items-center rounded-control bg-brand-soft text-brand-primary"><x-ui.icon name="media" size="size-4" /></span><p class="mt-1.5 text-sm font-semibold tabular-nums">{{ $clientFolder->media_references_count }}</p><p class="text-xs text-text-muted">Media</p></div>
                    <div class="px-1 py-3 text-center"><span class="mx-auto grid size-8 place-items-center rounded-control bg-brand-soft text-brand-primary"><x-ui.icon name="report" size="size-4" /></span><p class="mt-1.5 text-sm font-semibold tabular-nums">{{ $clientFolder->generated_reports_count }}</p><p class="text-xs text-text-muted">Reports</p></div>
                    <div class="px-1 py-3 text-center"><span class="mx-auto grid size-8 place-items-center rounded-control bg-brand-soft text-brand-primary"><x-ui.icon name="attachment" size="size-4" /></span><p class="mt-1.5 text-sm font-semibold tabular-nums">{{ $clientFolder->attachments_count }}</p><p class="text-xs text-text-muted">Documents</p></div>
                </div>

                <div class="p-4">
                    <div class="flex items-center justify-between gap-3 text-sm font-semibold"><span>Overall Progress</span><span class="tabular-nums text-brand-sidebar" data-folder-progress-label>{{ number_format($progress['percentage'], 0) }}%</span></div>
                    <div data-folder-progress-bar><x-ui.progress-bar :value="$progress['percentage']" class="mt-2" label="Overall client folder completion summary" /></div>
                    @if($progress['is_evaluated'])
                        <p class="mt-3 text-xs leading-5 text-text-muted">{{ $progress['completed'] }} of {{ $progress['total'] }} applicable required items completed.</p>
                        @if($progress['missing'])
                            <div class="mt-3 rounded-control bg-surface-muted p-3"><p class="text-xs font-semibold text-text-main">Pending requirements</p><ul class="mt-1.5 space-y-1 text-xs leading-5 text-text-muted">@foreach($progress['missing'] as $item)<li class="flex gap-2"><span aria-hidden="true">&bull;</span><span>{{ $item }}</span></li>@endforeach</ul></div>
                        @endif
                    @else
                        <p class="mt-3 text-xs leading-5 text-text-muted">No applicable completion results have been evaluated for this folder yet.</p>
                    @endif
                </div>
            </section>

            <section class="order-4 ui-panel overflow-hidden" aria-labelledby="recent-activity-title">
                <div class="flex items-center gap-2.5 px-4 pb-2 pt-4">
                    <span class="text-brand-primary"><x-ui.icon name="clock" size="size-5" /></span>
                    <h2 id="recent-activity-title" class="text-base font-semibold text-brand-sidebar">Recent Activity</h2>
                </div>
                @if($recentHistory->isEmpty())
                    <div class="p-4"><p class="text-sm font-semibold">No recent folder activity</p><p class="mt-1 text-xs leading-5 text-text-muted">Authorized folder events will appear here.</p></div>
                @else
                    <div class="space-y-1 px-4 pb-4">
                        @foreach($recentHistory as $event)
                            <article class="flex gap-3 rounded-control px-2 py-3 hover:bg-surface-muted">
                                <span class="mt-1.5 size-2 shrink-0 rounded-full bg-brand-primary" aria-hidden="true"></span>
                                <div class="min-w-0 flex-1"><p class="text-sm font-medium leading-5">{{ $event->description }}</p><p class="mt-1 text-xs text-text-muted">{{ $event->user?->full_name ?? 'System' }}</p></div>
                                <time class="w-20 shrink-0 text-right text-[0.68rem] leading-4 text-text-muted" datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->timezone($displayTimezone)->format('M j, Y') }}<br>{{ $event->created_at->timezone($displayTimezone)->format('g:i A') }}</time>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        </aside>
    </div>

    <x-ui.cibi-report-modal />
@endsection
