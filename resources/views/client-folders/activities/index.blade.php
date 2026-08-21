@extends('layouts.app')

@section('title', 'CI Activities')

@section('content')
    @php($personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null))

    <x-ui.breadcrumb :items="[
        ['label' => 'Dashboard', 'url' => route('home')],
        ['label' => 'Client Folders', 'url' => route('client-folders.index')],
        ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', [$clientFolder] + $personParams)],
        ['label' => 'CI Activities'],
    ]" />

    <x-ui.page-header title="CI Activities">
        <x-slot:description>Complete the required field-investigation checklist for {{ ($activePerson ?? null) ? $activePerson->full_name : $clientFolder->display_name }}. Activity states remain internal and do not replace the folder's On Progress or Completed status.</x-slot:description>
        <x-slot:actions><a href="{{ route('client-folders.show', [$clientFolder] + $personParams) }}" class="ui-button-secondary">Back to Folder</a></x-slot:actions>
    </x-ui.page-header>

    @if($activities->isEmpty())
        <x-ui.empty-state title="No CI activities available" description="No active activity records have been created for this client folder." icon="activity" />
    @else
        <section class="grid gap-5 lg:grid-cols-2" aria-label="CI activity checklist">
            @foreach($activities as $activity)
                <article class="ui-card flex min-w-0 flex-col p-5 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-3">
                            <span @class(['grid size-10 shrink-0 place-items-center rounded-full', 'bg-success-soft text-success' => $activity->status === App\Enums\ActivityStatus::Completed, 'bg-progress-soft text-progress' => $activity->status !== App\Enums\ActivityStatus::Completed])>
                                <x-ui.icon :name="$activity->status === App\Enums\ActivityStatus::Completed ? 'check' : 'activity'" size="size-5" />
                            </span>
                            <div class="min-w-0">
                                <h2 class="text-lg font-bold text-brand-sidebar">{{ $activity->name }}</h2>
                                @if($activity->definition?->is_required)<p class="mt-0.5 text-xs font-bold uppercase tracking-wide text-text-muted">Required activity</p>@endif
                            </div>
                        </div>
                        <x-ui.status-badge :status="$activity->status" />
                    </div>

                    <dl class="mt-5 grid gap-x-5 gap-y-3 text-sm sm:grid-cols-2">
                        <div><dt class="font-semibold text-text-muted">Visit date</dt><dd class="mt-0.5">{{ $activity->visit_date?->timezone(config('cims.display_timezone'))->format('M j, Y') ?? 'Not recorded' }}</dd></div>
                        <div><dt class="font-semibold text-text-muted">Time</dt><dd class="mt-0.5">{{ $activity->time_in ? substr($activity->time_in, 0, 5) : '—' }} to {{ $activity->time_out ? substr($activity->time_out, 0, 5) : '—' }}</dd></div>
                        <div><dt class="font-semibold text-text-muted">Visited by</dt><dd class="mt-0.5">{{ $activity->visited_by ?: 'Not recorded' }}</dd></div>
                        <div><dt class="font-semibold text-text-muted">Person met / contact</dt><dd class="mt-0.5">{{ $activity->person_met_contact ?: 'Not recorded' }}</dd></div>
                        <div><dt class="font-semibold text-text-muted">Notes</dt><dd class="mt-0.5">{{ $activity->notes_count }} recorded</dd></div>
                        <div><dt class="font-semibold text-text-muted">Supporting media</dt><dd class="mt-0.5">{{ $activity->media_references_count }} reference{{ $activity->media_references_count === 1 ? '' : 's' }}</dd></div>
                    </dl>

                    <div class="mt-4 rounded-control bg-surface-subtle p-3 text-sm leading-6 text-text-muted">
                        {{ $activity->remarks ? Illuminate\Support\Str::limit($activity->remarks, 160) : 'No remarks recorded.' }}
                    </div>

                    <div class="mt-auto flex flex-col gap-3 border-t border-ui-border pt-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-text-muted">Updated {{ $activity->updated_at->timezone(config('cims.display_timezone'))->format('M j, Y g:i A') }}</p>
                        <a href="{{ route('client-folders.activities.edit', [$clientFolder, $activity] + $personParams) }}" class="ui-button-primary">Edit Activity</a>
                    </div>
                </article>
            @endforeach
        </section>
    @endif
@endsection
