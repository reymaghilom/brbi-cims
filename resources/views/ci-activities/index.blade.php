@extends('layouts.app')

@section('title', 'CI Activities')

@php
    $statusOptions = ['' => 'All Statuses', 'pending' => 'Pending', 'scheduled' => 'Scheduled', 'follow_up' => 'Follow-up', 'completed' => 'Completed'];
    $personOptions = ['' => 'All Persons', 'applicant' => 'Applicant', 'co_maker' => 'Co-Maker'];
    $scheduleOptions = ['all' => 'All Dates', 'today' => 'Today', 'tomorrow' => 'Tomorrow', 'this_week' => 'This Week', 'overdue' => 'Overdue'];
    $sortOptions = ['earliest_schedule' => 'Earliest Schedule', 'latest_schedule' => 'Latest Schedule', 'recently_updated' => 'Recently Updated', 'client_name' => 'Client Name'];
    $tabOptions = ['all' => 'All Activities', 'due_today' => 'Due Today', 'scheduled' => 'Scheduled', 'follow_up' => 'Follow-up', 'completed' => 'Completed'];
@endphp

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'CI Activities']]" />
    <x-ui.page-header title="CI Activities">
        <x-slot:description>Manage and monitor your investigation activities across all clients.</x-slot:description>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <x-ui.summary-card label="Due Today" :value="$counts['due_today']" :hint="'Overdue: '.$counts['overdue']" icon="bell" tone="red" />
        <x-ui.summary-card label="Scheduled" :value="$counts['scheduled']" hint="Today & upcoming" icon="calendar" tone="folder" />
        <x-ui.summary-card label="Follow-up" :value="$counts['follow_up']" hint="Need follow-up" icon="clock" tone="amber" />
        <x-ui.summary-card label="Completed" :value="$counts['completed']" hint="This month" icon="check-circle" tone="green" />
        <x-ui.summary-card label="All Activities" :value="$counts['all']" hint="Total activities" icon="activity" tone="violet" />
    </div>

    <section class="mt-6 ui-panel overflow-hidden">
        <div class="border-b border-ui-border p-4 sm:p-5">
            <form method="GET" action="{{ route('ci-activities.index') }}" id="global-ci-filter" class="flex flex-col gap-3 lg:flex-row lg:items-end">
                <div class="min-w-0 flex-1">
                    <label for="global-ci-search" class="sr-only">Search client, person, or activity</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-text-muted" aria-hidden="true"><x-ui.icon name="search" size="size-4" /></span>
                        <input id="global-ci-search" type="search" name="search" value="{{ $filters['search'] }}" maxlength="150" class="ui-control min-h-10 py-2 pl-9" placeholder="Search client, person, or activity...">
                    </div>
                </div>
                <div class="w-full lg:w-44">
                    <label for="global-ci-status" class="ui-label">Status</label>
                    <select id="global-ci-status" name="status" class="ui-control min-h-10 py-2" onchange="this.form.submit()">
                        @foreach($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full lg:w-48">
                    <label for="global-ci-type" class="ui-label">Activity Type</label>
                    <select id="global-ci-type" name="activity_type" class="ui-control min-h-10 py-2" onchange="this.form.submit()">
                        <option value="" @selected($filters['activity_type'] === '')>All Types</option>
                        @foreach($definitions as $definition)
                            <option value="{{ $definition->code }}" @selected($filters['activity_type'] === $definition->code)>{{ $definition->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full lg:w-40">
                    <label for="global-ci-person" class="ui-label">Person</label>
                    <select id="global-ci-person" name="person" class="ui-control min-h-10 py-2" onchange="this.form.submit()">
                        @foreach($personOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['person'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full lg:w-40">
                    <label for="global-ci-schedule" class="ui-label">Schedule</label>
                    <select id="global-ci-schedule" name="schedule" class="ui-control min-h-10 py-2" onchange="this.form.submit()">
                        @foreach($scheduleOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['schedule'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <input type="hidden" name="tab" value="{{ $filters['tab'] }}">
                <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
                <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
                <a href="{{ route('ci-activities.index') }}" class="ui-button-secondary min-h-10 shrink-0 px-4 py-2"><x-ui.icon name="close" size="size-4" />Clear Filters</a>
            </form>
        </div>

        <div class="flex flex-col gap-3 border-b border-ui-border px-4 pt-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div role="tablist" aria-label="CI Activity tabs" class="flex gap-1 overflow-x-auto">
                @foreach($tabOptions as $value => $label)
                    @php
                        $tabQuery = array_merge(request()->query(), ['tab' => $value, 'page' => null]);
                        $tabCount = $counts[$value] ?? $counts['all'];
                    @endphp
                    <a href="{{ route('ci-activities.index', $tabQuery) }}" role="tab" aria-selected="{{ $filters['tab'] === $value ? 'true' : 'false' }}" class="flex min-h-11 shrink-0 items-center gap-2 border-b-2 px-3 text-sm font-semibold transition aria-selected:border-brand-primary aria-selected:text-brand-primary aria-[selected=false]:border-transparent aria-[selected=false]:text-text-muted">
                        {{ $label }}
                        <span @class(['rounded-full px-2 py-0.5 text-xs font-bold', 'bg-brand-primary text-white' => $filters['tab'] === $value, 'bg-surface-muted text-text-muted' => $filters['tab'] !== $value])>{{ $tabCount }}</span>
                    </a>
                @endforeach
            </div>
            <div class="flex shrink-0 items-center gap-2 pb-2 sm:pb-0">
                <label for="global-ci-sort" class="ui-label mb-0 shrink-0">Sort by</label>
                <select id="global-ci-sort" name="sort" form="global-ci-secondary-filter" class="ui-control min-h-9 w-48 py-1.5 text-sm" onchange="this.form.submit()">
                    @foreach($sortOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <form method="GET" action="{{ route('ci-activities.index') }}" id="global-ci-secondary-filter" class="hidden">
            @foreach(request()->except(['sort', 'per_page', 'page']) as $key => $value)
                @if(is_array($value))
                    @foreach($value as $item)<input type="hidden" name="{{ $key }}[]" value="{{ $item }}">@endforeach
                @else
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach
        </form>

        <div class="p-4 sm:p-5">
            @if($rows->isEmpty())
                <x-ui.empty-state title="No CI activities found" description="Try adjusting your filters or create activities inside a Client Folder." icon="activity" />
            @else
                <div class="hidden overflow-x-auto lg:block">
                    <table class="ui-table w-full text-sm">
                        <thead>
                            <tr>
                                <th class="px-3 py-3 text-left">Client / Loan</th>
                                <th class="px-3 py-3 text-left">Person</th>
                                <th class="px-3 py-3 text-left">Activity</th>
                                <th class="px-3 py-3 text-left">Status</th>
                                <th class="px-3 py-3 text-left">Schedule</th>
                                <th class="px-3 py-3 text-left">Progress</th>
                                <th class="px-3 py-3 text-left">Updated</th>
                                <th class="px-3 py-3 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ui-border">
                            @foreach($rows as $row)
                                <tr>
                                    <td class="px-3 py-3">
                                        <a href="{{ route('client-folders.show', $row->clientFolder) }}" class="block font-bold text-brand-primary hover:underline">{{ strtoupper($row->clientFolder->display_name) }}</a>
                                        @if($row->clientFolder->folder_number)<span class="block text-xs text-text-muted">{{ '#'.$row->clientFolder->folder_number }}</span>@endif
                                    </td>
                                    <td class="px-3 py-3">
                                        <div class="min-w-0">
                                            <span class="block text-xs font-semibold text-text-muted">{{ $row->personLabel }}</span>
                                            <span class="block truncate font-medium text-text-main">{{ $row->personName }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3">@include('ci-activities.partials.activity-cell', ['row' => $row])</td>
                                    <td class="px-3 py-3">@include('ci-activities.partials.status-badge', ['row' => $row])</td>
                                    <td class="px-3 py-3">@include('ci-activities.partials.schedule-cell', ['row' => $row])</td>
                                    <td class="px-3 py-3">@include('ci-activities.partials.progress-ring', ['numerator' => $row->progressNumerator, 'denominator' => $row->progressDenominator, 'percent' => $row->progressPercent])</td>
                                    <td class="px-3 py-3">
                                        <span class="block font-semibold text-text-main">{{ $row->updatedAt?->timezone(config('cims.display_timezone'))->format('M j, Y') }}</span>
                                        <span class="block text-text-muted">{{ $row->updatedAt?->timezone(config('cims.display_timezone'))->format('g:i A') }}</span>
                                    </td>
                                    <td class="px-3 py-3"><a href="{{ $row->openUrl }}" class="ui-button-secondary-compact">Open</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 lg:hidden">
                    @foreach($rows as $row)
                        <div class="rounded-card border border-ui-border bg-surface p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <a href="{{ route('client-folders.show', $row->clientFolder) }}" class="block font-bold text-brand-primary hover:underline">{{ strtoupper($row->clientFolder->display_name) }}</a>
                                    <span class="block text-xs font-semibold text-text-muted">{{ $row->personLabel }} &middot; {{ $row->personName }}</span>
                                </div>
                                @include('ci-activities.partials.status-badge', ['row' => $row])
                            </div>
                            <div class="mt-3 border-t border-ui-border pt-3">@include('ci-activities.partials.activity-cell', ['row' => $row])</div>
                            <div class="mt-3 grid grid-cols-2 gap-3 border-t border-ui-border pt-3 text-sm">
                                <div>
                                    <p class="ui-label mb-1">Schedule</p>
                                    @include('ci-activities.partials.schedule-cell', ['row' => $row])
                                </div>
                                <div>
                                    <p class="ui-label mb-1">Progress</p>
                                    @include('ci-activities.partials.progress-ring', ['numerator' => $row->progressNumerator, 'denominator' => $row->progressDenominator, 'percent' => $row->progressPercent])
                                </div>
                            </div>
                            <div class="mt-3 flex items-center justify-between border-t border-ui-border pt-3">
                                <div class="text-xs text-text-muted">
                                    <p class="font-semibold text-text-muted">Updated</p>
                                    <p>{{ $row->updatedAt?->timezone(config('cims.display_timezone'))->format('M j, Y \\a\\t g:i A') }}</p>
                                </div>
                                <a href="{{ $row->openUrl }}" class="ui-button-primary-compact">Open Activity</a>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-text-muted">Showing {{ $rows->firstItem() ?? 0 }} to {{ $rows->lastItem() ?? 0 }} of {{ $rows->total() }} activities</p>
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-1">
                            <a href="{{ $rows->previousPageUrl() ?? '#' }}" @class(['ui-action-icon-button', 'pointer-events-none opacity-40' => ! $rows->previousPageUrl()]) aria-label="Previous page"><x-ui.icon name="chevron-right" size="size-4" class="rotate-180" /></a>
                            @foreach($rows->getUrlRange(1, $rows->lastPage()) as $page => $url)
                                @if($page === 1 || $page === $rows->lastPage() || abs($page - $rows->currentPage()) <= 1)
                                    <a href="{{ $url }}" @class(['inline-flex size-9 items-center justify-center rounded-control text-sm font-semibold', 'bg-brand-primary text-white' => $page === $rows->currentPage(), 'text-text-main hover:bg-surface-muted' => $page !== $rows->currentPage()])>{{ $page }}</a>
                                @elseif(abs($page - $rows->currentPage()) === 2)
                                    <span class="px-1 text-text-muted">&hellip;</span>
                                @endif
                            @endforeach
                            <a href="{{ $rows->nextPageUrl() ?? '#' }}" @class(['ui-action-icon-button', 'pointer-events-none opacity-40' => ! $rows->nextPageUrl()]) aria-label="Next page"><x-ui.icon name="chevron-right" size="size-4" /></a>
                        </div>
                        <select name="per_page" form="global-ci-secondary-filter" class="ui-control min-h-9 w-28 py-1.5 text-sm" onchange="this.form.submit()">
                            @foreach($perPageOptions as $option)
                                <option value="{{ $option }}" @selected($filters['per_page'] === $option)>{{ $option }} / page</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @endif
        </div>
    </section>
@endsection
