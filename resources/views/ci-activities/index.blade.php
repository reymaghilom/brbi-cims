@extends('layouts.app')

@section('title', 'CI Activities')

@php
    $statusOptions = ['' => 'All Statuses', 'pending' => 'Pending', 'scheduled' => 'Scheduled', 'follow_up' => 'Follow-up', 'completed' => 'Completed'];
    $personOptions = ['' => 'All Persons', 'applicant' => 'Applicant', 'co_maker' => 'Co-Maker'];
    $scheduleOptions = ['all' => 'All Dates', 'today' => 'Today', 'tomorrow' => 'Tomorrow', 'this_week' => 'This Week', 'overdue' => 'Overdue'];
    $sortOptions = ['earliest_schedule' => 'Earliest Schedule', 'latest_schedule' => 'Latest Schedule', 'recently_updated' => 'Recently Updated', 'client_name' => 'Client Name'];
    $tabOptions = ['all' => 'All Activities', 'due_today' => 'Due Today', 'scheduled' => 'Scheduled', 'follow_up' => 'Follow-up', 'completed' => 'Completed'];

    // One expression for "the URL that selects this tab", used by both the KPI cards and the tab
    // strip below, so a KPI card can never drift from the tab it stands for. It is the exact URL
    // the tabs already built inline: every active filter rides along and only the page resets.
    $tabUrl = fn (string $value): string => route('ci-activities.index', array_merge(request()->query(), ['tab' => $value, 'page' => null]));
@endphp

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'CI Activities']]" />
    <x-ui.page-header title="CI Activities">
        <x-slot:description>Manage and monitor your investigation activities across all clients.</x-slot:description>
    </x-ui.page-header>

    {{-- Each KPI is a real link to the tab it already counts — the same $counts entry the tab
         strip shows and the same URL the tab link builds, so this adds no second filtering system
         and no second count query. The card matching the open tab carries aria-current="page",
         which is also what drives its active styling. --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <x-ui.summary-card label="Due Today" :value="$counts['due_today']" :hint="'Overdue: '.$counts['overdue']" icon="bell" tone="red" :url="$tabUrl('due_today')" :active="$filters['tab'] === 'due_today'" />
        <x-ui.summary-card label="Scheduled" :value="$counts['scheduled']" hint="Today & upcoming" icon="calendar" tone="folder" :url="$tabUrl('scheduled')" :active="$filters['tab'] === 'scheduled'" />
        <x-ui.summary-card label="Follow-up" :value="$counts['follow_up']" hint="Need follow-up" icon="clock" tone="amber" :url="$tabUrl('follow_up')" :active="$filters['tab'] === 'follow_up'" />
        <x-ui.summary-card label="Completed" :value="$counts['completed']" hint="This month" icon="check-circle" tone="green" :url="$tabUrl('completed')" :active="$filters['tab'] === 'completed'" />
        <x-ui.summary-card label="All Activities" :value="$counts['all']" hint="Total activities" icon="activity" tone="violet" :url="$tabUrl('all')" :active="$filters['tab'] === 'all'" />
    </div>

    <section class="mt-6 ui-panel overflow-hidden">
        <div class="border-b border-ui-border p-4 sm:p-5">
            <form method="GET" action="{{ route('ci-activities.index') }}" id="global-ci-filter" class="flex flex-col gap-3 lg:flex-row lg:items-end">
                <div class="relative min-w-0 flex-1" data-ci-client-search data-suggest-url="{{ route('reports.client-suggestions') }}">
                    <label for="global-ci-search" class="sr-only">Search client, person, or activity</label>
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-text-muted" aria-hidden="true"><x-ui.icon name="search" size="size-4" /></span>
                    <input id="global-ci-search" type="search" name="search" value="{{ $filters['search'] }}" maxlength="150" class="ui-control min-h-10 py-2 pl-9" placeholder="Search client, person, or activity..."
                           role="combobox" aria-expanded="false" aria-controls="global-ci-client-suggestions" aria-autocomplete="list" autocomplete="off" data-ci-client-input>
                    <ul id="global-ci-client-suggestions" role="listbox" aria-label="Client name suggestions" hidden
                        class="absolute inset-x-0 top-full z-30 mt-1 max-h-72 overflow-y-auto rounded-card border border-ui-border bg-surface p-1.5 shadow-float"
                        data-ci-client-suggestions></ul>
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
                    @php $tabCount = $counts[$value] ?? $counts['all']; @endphp
                    <a href="{{ $tabUrl($value) }}" role="tab" aria-selected="{{ $filters['tab'] === $value ? 'true' : 'false' }}" class="flex min-h-11 shrink-0 items-center gap-2 border-b-2 px-3 text-sm font-semibold transition aria-selected:border-brand-primary aria-selected:text-brand-primary aria-[selected=false]:border-transparent aria-[selected=false]:text-text-muted">
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

        {{-- data-ci-activities-listing is the hook app.js uses to intercept an ordinary
             left-click on pagination and AUTO-UPDATE just this region, exactly as the Client
             Folders browser does. Without JS these stay plain links and still work. --}}
        <div class="p-4 sm:p-5" data-ci-activities-listing>
            @include('ci-activities._listing')
        </div>
    </section>
@endsection
