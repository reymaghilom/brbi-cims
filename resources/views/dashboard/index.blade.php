@extends('layouts.app')

@section('title', 'Dashboard')

@php
    use App\Models\ClientFolder;

    $currentUser = auth()->user();

    // Shared by the Needs Attention card and its modal header: folders (the KPI) • overdue items.
    $needsAttentionSummary = $summary['needs_attention'] === 0
        ? 'Nothing overdue'
        : $summary['needs_attention'].' client '.Str::plural('folder', $summary['needs_attention']).' • '.$summary['needs_attention_items'].' overdue '.Str::plural('activity', $summary['needs_attention_items']);

    // Modal summary lines (singular/plural), one per KPI - each states that KPI's own counting unit.
    $countOf = fn (int $count, string $singular, ?string $plural = null): string => $count.' '.($count === 1 ? $singular : ($plural ?? Str::plural($singular)));
    $kpiSummaries = [
        'active' => $countOf($summary['assigned'], 'active client folder'),
        'in_progress' => $countOf($summary['in_progress'], 'client folder').' with incomplete mandatory requirements',
        'completed_this_month' => $countOf($summary['completed_this_month'], 'client folder').' completed this month',
        'reports_ready' => $countOf($summary['reports_ready'], 'completed report').' ready for release',
    ];

    // Every KPI opens its own detail modal when it has something to show (count > 0); at zero it
    // stays a plain card. Counting units stay distinct: folders, except Reports Ready (report records).
    // Icon tile tint per KPI, using the existing theme tokens only — no new palette is introduced.
    $kpis = [
        ['label' => 'Active Client Folders', 'value' => $summary['assigned'], 'icon' => 'folder', 'tone' => 'brand',
            'hint' => $summary['assigned'] === 0 ? 'No client folders available yet' : 'Client folders available for investigation',
            'modal' => $summary['assigned'] > 0 ? 'dashboard-active-folders-dialog' : null],
        ['label' => 'In Progress', 'value' => $summary['in_progress'], 'icon' => 'clock', 'tone' => 'amber',
            'hint' => $summary['in_progress'] === 0 ? 'Nothing in progress' : 'Active Investigations',
            'modal' => $summary['in_progress'] > 0 ? 'dashboard-in-progress-dialog' : null],
        // This KPI counts client folders (each folder once, however many overdue activities it
        // holds); the hint adds how many overdue activities/targets they hold.
        ['label' => 'Needs Attention', 'value' => $summary['needs_attention'], 'icon' => 'warning', 'tone' => 'danger',
            'hint' => $needsAttentionSummary,
            'modal' => $summary['needs_attention'] > 0 ? 'dashboard-needs-attention-dialog' : null, 'detail' => 'view overdue details'],
        ['label' => 'Completed This Month', 'value' => $summary['completed_this_month'], 'icon' => 'check-circle', 'tone' => 'success',
            'hint' => 'Completed in '.$today->format('F'),
            'modal' => $summary['completed_this_month'] > 0 ? 'dashboard-completed-month-dialog' : null],
        ['label' => 'Reports Ready', 'value' => $summary['reports_ready'], 'icon' => 'report', 'tone' => 'violet',
            'hint' => $summary['reports_ready'] === 0 ? 'No completed reports yet' : 'Completed Reports',
            'modal' => $summary['reports_ready'] > 0 ? 'dashboard-reports-ready-dialog' : null],
    ];

    // Interactive treatment per tone (Needs Attention is the reference): a quiet tint + ring on hover
    // and a matching "View details" cue. Literal class strings so the Tailwind build picks them up.
    $kpiInteractive = [
        'brand' => ['hover' => 'hover:bg-brand-soft/40 hover:ring-brand-primary/25', 'cue' => 'text-brand-primary'],
        'amber' => ['hover' => 'hover:bg-progress-soft/40 hover:ring-progress/25', 'cue' => 'text-progress'],
        'danger' => ['hover' => 'hover:bg-danger-soft/30 hover:ring-danger/25', 'cue' => 'text-danger'],
        'success' => ['hover' => 'hover:bg-success-soft/40 hover:ring-success/25', 'cue' => 'text-success'],
        'violet' => ['hover' => 'hover:bg-violet-50/70 hover:ring-violet-300/40', 'cue' => 'text-violet-700'],
    ];

    $kpiTones = [
        'brand' => 'bg-brand-soft text-brand-primary',
        'amber' => 'bg-progress-soft text-progress',
        'danger' => 'bg-danger-soft text-danger',
        'success' => 'bg-success-soft text-success',
        'violet' => 'bg-violet-50 text-violet-700',
    ];

    $statusTones = [
        'danger' => 'bg-danger-soft text-danger',
        'amber' => 'bg-progress-soft text-progress',
        'brand' => 'bg-brand-soft text-brand-primary',
        'neutral' => 'bg-surface-muted text-text-muted',
    ];
@endphp

@section('content')
    {{-- Greeting ------------------------------------------------------------------------------ --}}
    <section class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-6" aria-labelledby="dashboard-greeting">
        {{-- The time-of-day greeting already lives in the app header (see layouts.app), so it is
             deliberately not repeated here — this hero carries the page identity and the date. --}}
        <div class="min-w-0">
            <h1 id="dashboard-greeting" class="text-2xl font-bold tracking-tight text-text-main sm:text-3xl">Dashboard</h1>
            <p class="mt-1 text-sm text-text-muted">Here's your credit investigation overview for today.</p>
        </div>
        <div class="shrink-0 sm:text-right">
            <p class="flex items-center gap-2 text-sm font-bold text-text-main sm:justify-end">
                <x-ui.icon name="calendar" size="size-4" class="text-text-muted" />{{ $today->format('l, F j, Y') }}
            </p>
            <p class="mt-1 text-xs text-text-subtle">Stay focused. Every investigation helps build better decisions.</p>
        </div>
    </section>

    {{-- KPI row ------------------------------------------------------------------------------- --}}
    <section class="mt-5" aria-labelledby="dashboard-kpi-title">
        <h2 id="dashboard-kpi-title" class="sr-only">Workload summary</h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            @foreach($kpis as $kpi)
                @php $kpiModal = $kpi['modal'] ?? null; @endphp
                {{-- A KPI with details renders as a real button: pointer cursor, a quiet hover
                     lift/tint in its own tone, the shared brand focus ring and a chevron cue. At zero it
                     stays a plain, non-interactive card. --}}
                <{{ $kpiModal ? 'button' : 'article' }} @if($kpiModal) type="button" data-modal-open="{{ $kpiModal }}" aria-haspopup="dialog" data-kpi-clickable @endif class="ui-card flex items-start gap-3.5 p-4 text-left sm:p-5 {{ $kpiModal ? 'group w-full min-w-0 cursor-pointer ring-1 ring-transparent transition duration-150 '.$kpiInteractive[$kpi['tone']]['hover'].' motion-safe:hover:-translate-y-px focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/40' : '' }}" aria-label="{{ $kpi['label'] }}{{ $kpiModal ? ': '.$kpi['value'].', '.($kpi['detail'] ?? 'view details') : '' }}">
                    <span class="grid size-11 shrink-0 place-items-center rounded-card {{ $kpiTones[$kpi['tone']] }}" aria-hidden="true">
                        <x-ui.icon :name="$kpi['icon']" size="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-text-muted">{{ $kpi['label'] }}</p>
                        <p class="mt-0.5 text-3xl font-bold tabular-nums tracking-tight text-text-main">{{ $kpi['value'] }}</p>
                        <p class="mt-1 text-xs leading-4 text-text-subtle">{{ $kpi['hint'] }}</p>
                        @if($kpiModal)
                            <p class="mt-2 inline-flex items-center gap-0.5 text-xs font-semibold {{ $kpiInteractive[$kpi['tone']]['cue'] }}" aria-hidden="true">View details<x-ui.icon name="chevron-right" size="size-3.5" class="transition group-hover:translate-x-0.5" /></p>
                        @endif
                    </div>
                </{{ $kpiModal ? 'button' : 'article' }}>
            @endforeach
        </div>
    </section>

    {{-- Analytics row ------------------------------------------------------------------------- --}}
    <section class="mt-4 grid gap-3 xl:grid-cols-12" aria-labelledby="dashboard-analytics-title">
        <h2 id="dashboard-analytics-title" class="sr-only">Investigation analytics</h2>

        <article class="ui-card p-4 sm:p-5 xl:col-span-6" aria-labelledby="trend-title">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex min-w-0 items-start gap-2.5">
                    <span class="grid size-8 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-primary" aria-hidden="true"><x-ui.icon name="chart" size="size-4" /></span>
                    <div class="min-w-0">
                        <h3 id="trend-title" class="font-bold text-text-main">CI Completion Trend</h3>
                        <p class="text-xs text-text-muted">Number of completed investigations</p>
                    </div>
                </div>
                {{-- Every range is rendered up front (see DashboardData::for()'s `trends`), so
                     app.js switches ranges by simply showing the matching pre-rendered chart —
                     instantly, with no request and no loading state. These stay real links to the
                     same GET parameter the controller already re-reads, so without JS they still
                     work exactly as before; app.js only intercepts the click. --}}
                <div class="flex shrink-0 gap-1 rounded-control bg-surface-muted p-1" role="group" aria-label="Trend range" data-trend-tabs>
                    @foreach($trendRanges as $key => $label)
                        <a href="{{ route('home', ['range' => $key]) }}" data-trend-tab="{{ $key }}"
                            @class([
                                'min-h-8 rounded-control px-3 py-1.5 text-xs font-semibold transition',
                                'bg-brand-primary text-white shadow-sm' => $trendRange === $key,
                                'text-text-muted hover:bg-surface hover:text-brand-primary' => $trendRange !== $key,
                            ])
                            @if($trendRange === $key) aria-current="true" @endif>{{ $label }}</a>
                    @endforeach
                </div>
            </div>
            @foreach($trends as $key => $rangeTrend)
                <div data-trend-panel="{{ $key }}"@unless($trendRange === $key) hidden @endunless>
                    @include('dashboard._trend-chart', ['trend' => $rangeTrend])
                </div>
            @endforeach
        </article>

        <article class="ui-card p-4 sm:p-5 xl:col-span-3" aria-labelledby="workload-title">
            <div class="flex items-start gap-2.5">
                <span class="grid size-8 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-primary" aria-hidden="true"><x-ui.icon name="chart" size="size-4" /></span>
                <h3 id="workload-title" class="font-bold text-text-main">Workload by Status</h3>
            </div>
            @if($workload['total'] === 0)
                <x-ui.empty-state class="mt-4" title="No client folders yet" description="The workload chart appears once active client folders exist in the workspace." icon="folder" />
            @else
                @include('dashboard._workload-donut')
            @endif
        </article>

        <article class="ui-card p-4 sm:p-5 xl:col-span-3" aria-labelledby="progress-title">
            <div class="flex items-start gap-2.5">
                <span class="grid size-8 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-primary" aria-hidden="true"><x-ui.icon name="activity" size="size-4" /></span>
                <h3 id="progress-title" class="font-bold text-text-main">CI Activity Progress</h3>
            </div>
            <ul class="mt-4 space-y-4">
                @foreach($activityProgress as $bar)
                    <li>
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="min-w-0 truncate text-sm font-semibold text-text-main">{{ $bar['label'] }}</p>
                            <p class="shrink-0 text-sm font-bold tabular-nums text-text-main">{{ $bar['percent'] }}%</p>
                        </div>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-surface-muted" role="img"
                            aria-label="{{ $bar['label'] }}: {{ $bar['percent'] }} percent complete.">
                            <span class="block h-full rounded-full bg-brand-primary" style="width: {{ $bar['percent'] }}%"></span>
                        </div>
                        <p class="mt-1.5 text-xs text-text-subtle">
                            @if($bar['applicable'] === 0)
                                No applicable {{ $bar['unit'] }} yet
                            @else
                                {{ $bar['completed'] }} of {{ $bar['applicable'] }} {{ $bar['unit'] }}
                            @endif
                        </p>
                    </li>
                @endforeach
            </ul>
        </article>
    </section>

    {{-- Work row ------------------------------------------------------------------------------ --}}
    <section class="mt-4 grid gap-3 xl:grid-cols-12" aria-labelledby="dashboard-work-title">
        <h2 id="dashboard-work-title" class="sr-only">Today's work</h2>

        <article class="ui-card overflow-hidden xl:col-span-8" aria-labelledby="work-today-title">
            <header class="flex flex-wrap items-start justify-between gap-3 border-b border-ui-border p-4 sm:p-5">
                <div class="flex min-w-0 items-start gap-2.5">
                    <span class="grid size-8 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-primary" aria-hidden="true"><x-ui.icon name="activity" size="size-4" /></span>
                    <div class="min-w-0">
                        <h3 id="work-today-title" class="font-bold text-text-main">My Work Today</h3>
                        <p class="text-xs text-text-muted">Your CI activities that need action</p>
                    </div>
                </div>
                <a href="{{ route('ci-activities.index') }}" class="ui-button-secondary-compact shrink-0">View All</a>
            </header>

            @if($workToday === [])
                <x-ui.empty-state class="m-4 sm:m-5" title="You're all caught up" description="No items need your attention right now." icon="check-circle" />
            @else
                {{-- Table from md up; the same rows become stacked cards below that so a narrow
                     screen never has to scroll sideways to reach the action. --}}
                <div class="hidden md:block">
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th scope="col">Client Name</th>
                                <th scope="col">Pending Activity</th>
                                <th scope="col">Status</th>
                                <th scope="col">Last Activity</th>
                                <th scope="col"><span class="sr-only">Action</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($workToday as $item)
                                <tr>
                                    <td>
                                        <p class="font-bold text-text-main">{{ $item['client'] }}</p>
                                        @if($item['person'])<p class="text-xs text-text-muted">Co-Maker: {{ $item['person'] }}</p>@endif
                                    </td>
                                    <td class="text-text-muted">{{ $item['activity'] }}</td>
                                    <td><span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold {{ $statusTones[$item['tone']] }}">{{ $item['status'] }}</span></td>
                                    <td class="whitespace-nowrap text-xs text-text-muted">{{ $item['updated_at']?->timezone(config('cims.display_timezone'))->format('M j, Y g:i A') }}</td>
                                    <td class="text-right"><a href="{{ $item['url'] }}" class="ui-button-secondary-compact">{{ $item['action'] }}</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <ul class="divide-y divide-ui-border md:hidden">
                    @foreach($workToday as $item)
                        <li class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate font-bold text-text-main">{{ $item['client'] }}</p>
                                    @if($item['person'])<p class="truncate text-xs text-text-muted">Co-Maker: {{ $item['person'] }}</p>@endif
                                </div>
                                <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-bold {{ $statusTones[$item['tone']] }}">{{ $item['status'] }}</span>
                            </div>
                            <p class="mt-2 text-sm text-text-muted">{{ $item['activity'] }}</p>
                            <p class="mt-1 text-xs text-text-subtle">{{ $item['updated_at']?->timezone(config('cims.display_timezone'))->format('M j, Y g:i A') }}</p>
                            <a href="{{ $item['url'] }}" class="ui-button-secondary mt-3 w-full">{{ $item['action'] }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </article>

        <article class="ui-card p-4 sm:p-5 xl:col-span-4" aria-labelledby="recent-activity-title">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 items-start gap-2.5">
                    <span class="grid size-8 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-primary" aria-hidden="true"><x-ui.icon name="clock" size="size-4" /></span>
                    <h3 id="recent-activity-title" class="font-bold text-text-main">Recent Activity</h3>
                </div>
            </div>

            @if($recentActivity === [])
                <x-ui.empty-state class="mt-4" title="No recent activity" description="Actions on your assigned clients appear here." icon="clock" />
            @else
                {{-- Same timeline the Client Folder's own Recent Activity uses (see
                     client-folders/partials/recent-activity-body.blade.php): one dot node per entry,
                     joined by a dashed connector that is deliberately not drawn on the last item, so
                     the line stops at the final activity instead of trailing past it. --}}
                <ol class="relative mt-6 space-y-0">
                    @foreach($recentActivity as $event)
                        <li class="relative grid grid-cols-[1rem_1fr] gap-3 pb-6 last:pb-0">
                            @unless($loop->last)<span class="absolute bottom-0 left-[0.4375rem] top-4 border-l border-dashed border-ui-border-strong" aria-hidden="true"></span>@endunless
                            <span class="relative z-10 mt-1 size-3.5 rounded-full border-2 border-white bg-brand-primary shadow-sm" aria-hidden="true"></span>
                            <div class="min-w-0">
                                <p class="text-sm font-bold leading-5 text-text-main">{{ $event['label'] }}</p>
                                <p class="mt-1 break-words text-xs leading-5 text-text-muted">{{ $event['client'] }}</p>
                                {{-- Actor, then the activity's own persisted timestamp in the display
                                     timezone, in the project's existing "M j, Y · g:i A" format. --}}
                                <p class="mt-1 text-xs leading-5 text-text-muted">{{ $event['user'] ?? '—' }}<br>{{ $event['at']?->format('M j, Y · g:i A') }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>

                @if($recentActivityHasMore)
                    <div class="mt-5 border-t border-ui-border pt-4">
                        <button type="button" class="block w-full text-center text-sm font-bold text-brand-primary hover:underline" data-modal-open="dashboard-recent-activity-dialog">View All</button>
                    </div>
                @endif
            @endif
        </article>
    </section>

    {{-- Quick actions ------------------------------------------------------------------------- --}}
    <section class="ui-card mt-4 flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-center lg:justify-between" aria-labelledby="quick-actions-title">
        <div class="flex min-w-0 items-start gap-2.5">
            <span class="grid size-8 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-primary" aria-hidden="true"><x-ui.icon name="activity" size="size-4" /></span>
            <div class="min-w-0">
                <h2 id="quick-actions-title" class="font-bold text-text-main">Quick Actions</h2>
                <p class="text-xs text-text-muted">Common tasks to help you get started</p>
            </div>
        </div>
        <div class="grid gap-2 sm:grid-cols-3 lg:flex lg:shrink-0 lg:gap-2.5">
            @can('create', ClientFolder::class)
                <a href="{{ route('client-folders.index') }}" class="ui-button-primary w-full lg:w-auto"><x-ui.icon name="plus" size="size-4" />New Client Folder</a>
            @endcan
            <a href="{{ route('ci-activities.index') }}" class="ui-button-secondary w-full lg:w-auto"><x-ui.icon name="clock" size="size-4" />Open Pending CI</a>
            <a href="{{ route('reports.index') }}" class="ui-button-secondary w-full lg:w-auto"><x-ui.icon name="report" size="size-4" />View Reports</a>
        </div>
    </section>

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
@endsection
