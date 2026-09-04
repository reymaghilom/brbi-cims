@extends('layouts.app')

@section('title', 'Dashboard')

@php
    use App\Models\ClientFolder;

    $currentUser = auth()->user();

    // Icon tile tint per KPI, using the existing theme tokens only — no new palette is introduced.
    $kpis = [
        ['label' => 'Active Client Folders', 'value' => $summary['assigned'], 'icon' => 'folder', 'tone' => 'brand',
            'hint' => $summary['assigned'] === 0 ? 'No client folders available yet' : 'Client folders available for investigation'],
        ['label' => 'In Progress', 'value' => $summary['in_progress'], 'icon' => 'clock', 'tone' => 'amber',
            'hint' => $summary['in_progress'] === 0 ? 'Nothing in progress' : 'Investigations already underway'],
        // This KPI counts client folders (each folder once, however many overdue activities it
        // holds), so the hint describes folders rather than activities.
        ['label' => 'Needs Attention', 'value' => $summary['needs_attention'], 'icon' => 'warning', 'tone' => 'danger',
            'hint' => $summary['needs_attention'] === 0
                ? 'Nothing overdue'
                : $summary['needs_attention'].' client '.Str::plural('folder', $summary['needs_attention']).' with overdue '.Str::plural('activity', $summary['needs_attention'])],
        ['label' => 'Completed This Month', 'value' => $summary['completed_this_month'], 'icon' => 'check-circle', 'tone' => 'success',
            'hint' => 'Completed in '.$today->format('F')],
        ['label' => 'Reports Ready', 'value' => $summary['reports_ready'], 'icon' => 'report', 'tone' => 'violet',
            'hint' => $summary['reports_ready'] === 0 ? 'No generated reports yet' : 'Ready for release'],
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
            <p class="mt-1 text-xs text-text-subtle">Stay focused. Every investigation builds a safer community.</p>
        </div>
    </section>

    {{-- KPI row ------------------------------------------------------------------------------- --}}
    <section class="mt-5" aria-labelledby="dashboard-kpi-title">
        <h2 id="dashboard-kpi-title" class="sr-only">Workload summary</h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            @foreach($kpis as $kpi)
                <article class="ui-card flex items-start gap-3.5 p-4 sm:p-5" aria-label="{{ $kpi['label'] }}">
                    <span class="grid size-11 shrink-0 place-items-center rounded-card {{ $kpiTones[$kpi['tone']] }}" aria-hidden="true">
                        <x-ui.icon :name="$kpi['icon']" size="size-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-text-muted">{{ $kpi['label'] }}</p>
                        <p class="mt-0.5 text-3xl font-bold tabular-nums tracking-tight text-text-main">{{ $kpi['value'] }}</p>
                        <p class="mt-1 text-xs leading-4 text-text-subtle">{{ $kpi['hint'] }}</p>
                    </div>
                </article>
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
                {{-- Plain links: the range is a GET parameter the controller re-reads, so the chart
                     stays server-rendered and needs no client-side state. --}}
                <div class="flex shrink-0 gap-1 rounded-control bg-surface-muted p-1" role="group" aria-label="Trend range">
                    @foreach($trendRanges as $key => $label)
                        <a href="{{ route('home', ['range' => $key]) }}"
                            @class([
                                'min-h-8 rounded-control px-3 py-1.5 text-xs font-semibold transition',
                                'bg-brand-primary text-white shadow-sm' => $trendRange === $key,
                                'text-text-muted hover:bg-surface hover:text-brand-primary' => $trendRange !== $key,
                            ])
                            @if($trendRange === $key) aria-current="true" @endif>{{ $label }}</a>
                    @endforeach
                </div>
            </div>
            @include('dashboard._trend-chart')
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
                <a href="{{ route('client-folders.index') }}" class="ui-button-secondary-compact shrink-0">View All</a>
            </div>

            @if($recentActivity === [])
                <x-ui.empty-state class="mt-4" title="No recent activity" description="Actions on your assigned clients appear here." icon="clock" />
            @else
                <ol class="mt-4 space-y-4">
                    @foreach($recentActivity as $event)
                        <li class="flex gap-3">
                            <span class="grid size-8 shrink-0 place-items-center rounded-control bg-surface-muted text-text-muted" aria-hidden="true"><x-ui.icon :name="$event['icon']" size="size-4" /></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-bold leading-5 text-text-main">{{ $event['label'] }}</p>
                                <p class="truncate text-xs text-text-muted">{{ $event['client'] }}@if($event['user']) · {{ $event['user'] }}@endif</p>
                            </div>
                            <p class="shrink-0 text-xs text-text-subtle">{{ $event['at']?->diffForHumans(['short' => true]) }}</p>
                        </li>
                    @endforeach
                </ol>
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
@endsection
