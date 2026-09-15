<div data-dashboard-refresh-region="detail-modals">
@if($needsAttention !== [])
    {{-- Needs Attention detail: every overdue activity / Bank / Coop institution / Asset office,
         grouped by Client Folder, oldest due first. Same modal pattern as Recent Activity. --}}
    <x-ui.modal id="dashboard-needs-attention-dialog" title="Needs Attention" :description="$needsAttentionSummary" size="max-w-2xl">
        <p class="-mt-1 mb-3 text-xs text-text-subtle" data-needs-attention-helper>Oldest overdue activities are shown first.</p>
        <div class="space-y-3" data-needs-attention-list>
            @foreach($needsAttention as $group)
                <section class="overflow-hidden rounded-card border border-ui-border" aria-label="{{ $group['client'] }}" data-needs-attention-folder>
                    <h3 class="flex items-center gap-2 border-b border-ui-border bg-surface-subtle px-3.5 py-2 text-sm font-bold text-brand-sidebar">
                        <x-ui.icon name="folder" size="size-4" class="shrink-0 text-text-muted" />
                        <span class="min-w-0 break-words">{{ $group['client'] }}</span>
                        <span class="ml-auto shrink-0 text-xs font-semibold text-text-muted">{{ count($group['items']) }} overdue</span>
                    </h3>
                    <ul class="divide-y divide-ui-border">
                        @foreach($group['items'] as $item)
                            @php
                                // Existing tones only: amber for 0–2 days, danger tint for 3–5, solid danger for 6+.
                                $overdueTone = match (true) {
                                    $item['days_overdue'] >= 6 => 'bg-danger text-white',
                                    $item['days_overdue'] >= 3 => 'bg-danger-soft text-danger',
                                    default => 'bg-progress-soft text-progress',
                                };
                                $overdueText = $item['days_overdue'] === 0 ? 'Overdue today' : 'Overdue by '.$item['days_overdue'].' '.Str::plural('day', $item['days_overdue']);
                            @endphp
                            <li data-needs-attention-item>
                                {{-- The whole row is one link to that exact person's CI Activities. --}}
                                <a href="{{ $item['url'] }}" class="group flex cursor-pointer items-start gap-3 px-3.5 py-3 transition duration-150 hover:bg-brand-soft/40 focus-visible:bg-brand-soft/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-primary/40" aria-label="{{ $item['label'] }}, {{ $item['person'] }}, due {{ $item['due'] }}, {{ $item['status'] }}, {{ $overdueText }}. Open CI Activities">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex min-w-0 flex-col gap-1.5 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                                            <p class="min-w-0 break-words text-sm font-semibold leading-5 text-text-main group-hover:text-brand-primary">{{ $item['label'] }}</p>
                                            <span class="w-fit shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold {{ $overdueTone }}" data-overdue-badge>{{ $overdueText }}</span>
                                        </div>
                                        <p class="mt-1 text-xs font-semibold leading-5 text-text-muted" data-needs-attention-person>{{ $item['person'] }}</p>
                                        <p class="text-xs leading-5 text-text-muted" data-needs-attention-meta>Due: {{ $item['due'] }} <span aria-hidden="true">&bull;</span> {{ $item['status'] }}</p>
                                    </div>
                                    <x-ui.icon name="chevron-right" size="size-4" class="mt-0.5 shrink-0 text-text-subtle transition group-hover:translate-x-0.5 group-hover:text-brand-primary" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    </x-ui.modal>
@endif

{{-- KPI detail modals, built on the Needs Attention pattern: summary line, helper line, then
     actionable rows (x-ui.detail-row). Each lists exactly the set its card counts. --}}
@if($kpiDetails['active'] !== [])
    <x-ui.modal id="dashboard-active-folders-dialog" title="Active Client Folders" :description="$kpiSummaries['active']" size="max-w-2xl">
        <p class="-mt-1 mb-3 text-xs text-text-subtle" data-kpi-detail-helper>Most recently updated first. Progress counts mandatory investigation requirements.</p>
        <ul class="divide-y divide-ui-border overflow-hidden rounded-card border border-ui-border" data-kpi-detail-list="active">
            @foreach($kpiDetails['active'] as $folder)
                <x-ui.detail-row :url="$folder['url']" :title="$folder['client']" icon="folder"
                    :badge="$folder['progress'] ? $folder['progress']['percent'].'% complete' : null"
                    :badge-class="$folder['status'] === 'Completed' ? 'bg-success-soft text-success' : 'bg-brand-soft text-brand-primary'"
                    :label="$folder['client'].', '.$folder['status'].'. Open client folder'" data-kpi-detail-row>
                    <p class="mt-1 text-xs leading-5 text-text-muted">{{ $folder['status'] }}@if($folder['ci']) <span aria-hidden="true">&bull;</span> <span class="font-semibold tracking-tight text-text-main">{{ $folder['ci'] }}</span>@endif @if($folder['updated'])<span aria-hidden="true">&bull;</span> Updated {{ $folder['updated'] }}@endif</p>
                </x-ui.detail-row>
            @endforeach
        </ul>
    </x-ui.modal>
@endif

@if($kpiDetails['in_progress'] !== [])
    <x-ui.modal id="dashboard-in-progress-dialog" title="In Progress" :description="$kpiSummaries['in_progress']" size="max-w-2xl">
        <p class="-mt-1 mb-3 text-xs text-text-subtle" data-kpi-detail-helper>Missing mandatory requirements per folder. Asset Check is optional and never listed.</p>
        <ul class="divide-y divide-ui-border overflow-hidden rounded-card border border-ui-border" data-kpi-detail-list="in_progress">
            @foreach($kpiDetails['in_progress'] as $folder)
                <x-ui.detail-row :url="$folder['url']" :title="$folder['client']" icon="folder"
                    :badge="$folder['progress']['percent'].'% complete'" badge-class="bg-progress-soft text-progress"
                    :label="$folder['client'].', '.$folder['progress']['percent'].' percent complete, '.count($folder['progress']['missing']).' missing. Open client folder'" data-kpi-detail-row>
                    <p class="mt-1 text-xs leading-5 text-text-muted">{{ $folder['progress']['completed'] }} of {{ $folder['progress']['total'] }} mandatory requirements complete</p>
                    <p class="mt-1.5 text-[11px] font-bold uppercase tracking-wide text-text-subtle">Missing</p>
                    <ul class="mt-1 flex flex-wrap gap-1.5" data-missing-requirements>
                        @foreach($folder['progress']['missing'] as $missing)
                            <li class="max-w-full break-words rounded-control bg-surface-muted px-2 py-0.5 text-xs font-medium text-text-main">{{ $missing }}</li>
                        @endforeach
                    </ul>
                </x-ui.detail-row>
            @endforeach
        </ul>
    </x-ui.modal>
@endif

@if($kpiDetails['completed_this_month'] !== [])
    <x-ui.modal id="dashboard-completed-month-dialog" title="Completed This Month" :description="$kpiSummaries['completed_this_month']" size="max-w-2xl">
        <p class="-mt-1 mb-3 text-xs text-text-subtle" data-kpi-detail-helper>Newest completion first.</p>
        <ul class="divide-y divide-ui-border overflow-hidden rounded-card border border-ui-border" data-kpi-detail-list="completed_this_month">
            @foreach($kpiDetails['completed_this_month'] as $folder)
                <x-ui.detail-row :url="$folder['url']" :title="$folder['client']" icon="check-circle" badge="Completed" badge-class="bg-success-soft text-success"
                    :label="$folder['client'].', completed '.$folder['completed_on'].'. Open client folder'" data-kpi-detail-row>
                    <p class="mt-1 text-xs leading-5 text-text-muted">Completed {{ $folder['completed_on'] }}@if($folder['ci']) <span aria-hidden="true">&bull;</span> <span class="font-semibold tracking-tight text-text-main">{{ $folder['ci'] }}</span>@endif</p>
                </x-ui.detail-row>
            @endforeach
        </ul>
    </x-ui.modal>
@endif

@if($kpiDetails['reports_ready'] !== [])
    <x-ui.modal id="dashboard-reports-ready-dialog" title="Reports Ready" :description="$kpiSummaries['reports_ready']" size="max-w-2xl">
        <p class="-mt-1 mb-3 text-xs text-text-subtle" data-kpi-detail-helper>Completed reports, grouped by client folder and person. Newest completion first.</p>
        <div class="space-y-3" data-kpi-detail-list="reports_ready">
            @foreach($kpiDetails['reports_ready'] as $group)
                <section class="overflow-hidden rounded-card border border-ui-border" aria-label="{{ $group['client'] }}" data-reports-ready-folder>
                    <h3 class="flex items-center gap-2 border-b border-ui-border bg-surface-subtle px-3.5 py-2 text-sm font-bold text-brand-sidebar">
                        <x-ui.icon name="folder" size="size-4" class="shrink-0 text-text-muted" />
                        <span class="min-w-0 break-words">{{ $group['client'] }}</span>
                        <span class="ml-auto shrink-0 text-xs font-semibold text-text-muted">{{ $countOf($group['count'], 'report') }}</span>
                    </h3>
                    @foreach($group['people'] as $person)
                        <p class="border-b border-ui-border bg-surface px-3.5 pb-1 pt-2.5 text-xs font-bold uppercase tracking-wide text-text-subtle" data-reports-ready-person>{{ $person['person'] }}</p>
                        <ul class="divide-y divide-ui-border border-b border-ui-border last:border-b-0">
                            @foreach($person['reports'] as $report)
                                {{-- The report's web output opens beside the Dashboard, so this
                                     modal is still open behind it. --}}
                                <x-ui.detail-row :url="$report['url']" :method="$report['method']" :fields="$report['fields']" :new-tab="true"
                                    :title="$report['label']" :icon="$report['icon']" badge="Completed" badge-class="bg-success-soft text-success"
                                    :label="$report['label'].', '.$person['person'].', completed '.$report['completed'].'. Open report'" data-kpi-detail-row>
                                    @if($report['completed'])<p class="mt-1 text-xs leading-5 text-text-muted">Completed {{ $report['completed'] }}</p>@endif
                                </x-ui.detail-row>
                            @endforeach
                        </ul>
                    @endforeach
                </section>
            @endforeach
        </div>
    </x-ui.modal>
@endif

@if($recentActivityHasMore)
    {{-- Match the established Client Folder Recent Activity behavior: View All expands the
         complete newest-first history in a modal instead of navigating to Client Folders. --}}
    <x-ui.modal id="dashboard-recent-activity-dialog" title="Recent Activity" description="Newest activity first." size="max-w-2xl">
        <ol class="relative space-y-0" aria-label="Complete recent activity history">
            @foreach($recentActivityAll as $event)
                <li class="relative grid min-w-0 grid-cols-[1rem_minmax(0,1fr)] gap-3 pb-6 last:pb-0">
                    @if(! $loop->last)<span class="absolute bottom-0 left-[0.4375rem] top-4 border-l border-dashed border-ui-border-strong" aria-hidden="true"></span>@endif
                    <span class="relative z-10 mt-1 size-3.5 rounded-full border-2 border-white bg-brand-primary shadow-sm" aria-hidden="true"></span>
                    <article class="min-w-0 rounded-control border border-ui-border bg-surface-subtle px-3.5 py-3">
                        <div class="flex min-w-0 flex-wrap items-start justify-between gap-x-4 gap-y-1">
                            <p class="min-w-0 break-words text-sm font-bold leading-5 text-text-main">{{ $event['label'] }}</p>
                            <time class="shrink-0 text-xs font-semibold leading-5 text-text-muted" datetime="{{ $event['at']?->toIso8601String() }}">{{ $event['at']?->format('M j, Y') }}</time>
                        </div>
                        <p class="mt-1 min-w-0 break-words text-sm leading-5 text-text-muted">{{ $event['client'] }}</p>
                        <p class="mt-1 flex min-w-0 flex-wrap items-center gap-x-1.5 text-xs leading-5 text-text-muted"><span class="break-words">{{ $event['user'] ?? 'System' }}</span><span aria-hidden="true">&middot;</span><time datetime="{{ $event['at']?->toIso8601String() }}">{{ $event['at']?->format('g:i A') }}</time></p>
                    </article>
                </li>
            @endforeach
        </ol>
    </x-ui.modal>
@endif
</div>
