@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <section class="mb-4" aria-labelledby="dashboard-summary-title">
        <h2 id="dashboard-summary-title" class="sr-only">Dashboard summary</h2>
        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.summary-card compact label="Total Client Folders" :value="$summary['total']" icon="folder" tone="folder" :hint="$summary['total'] === 0 ? 'No client folders yet.' : 'Active authorized folders'" data-dashboard-stat="total" />
            <x-ui.summary-card compact label="On Progress" :value="$summary['on_progress']" icon="activity" tone="amber" :hint="$summary['on_progress'] === 0 ? 'No folders currently in progress.' : 'Folders requiring completion'" data-dashboard-stat="on_progress" />
            <x-ui.summary-card compact label="Completed" :value="$summary['completed']" icon="check" :hint="$summary['completed'] === 0 ? 'No completed folders yet.' : 'All applicable requirements completed'" data-dashboard-stat="completed" />
            <x-ui.summary-card compact label="Reports Generated" :value="$summary['reports_generated']" icon="report" :hint="$summary['reports_generated'] === 0 ? 'No generated reports yet.' : 'Completed report artifacts'" data-dashboard-stat="reports_generated" />
        </div>
    </section>

    @include('dashboard._folder-browser')
@endsection
