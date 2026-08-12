@extends('layouts.app')

@section('title', $activity->name)

@section('content')
    <x-ui.breadcrumb :items="[
        ['label' => 'Client Folders', 'url' => route('client-folders.index')],
        ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', $clientFolder)],
        ['label' => 'CI Activities', 'url' => route('client-folders.activities.index', $clientFolder)],
        ['label' => $activity->name],
    ]" />

    <x-ui.page-header :title="$activity->name">
        <x-slot:description>Record the field result and preserve an auditable, append-only note history.</x-slot:description>
        <x-slot:actions><x-ui.status-badge :status="$activity->status" /><a href="{{ route('client-folders.activities.index', $clientFolder) }}" class="ui-button-secondary">All Activities</a></x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(20rem,0.7fr)]">
        <form method="POST" action="{{ route('client-folders.activities.update', [$clientFolder, $activity]) }}" class="min-w-0 space-y-6" data-unsaved-form>
            @csrf
            @method('PUT')

            <x-ui.form-section title="Activity Status and Visit" description="Visit details are optional for Not Started activities. Completed activities require visit date, visited by, and remarks.">
                <x-form.select name="status" label="Internal Activity Status" :options="collect($statuses)->mapWithKeys(fn ($status) => [$status->value => str($status->value)->replace('_', ' ')->title()->toString()])->all()" :selected="$activity->status->value" required />
                <x-form.input name="visit_date" label="Visit Date" type="date" :value="$activity->visit_date?->format('Y-m-d')" />
                <x-form.input name="time_in" label="Time In" type="time" :value="$activity->time_in ? substr($activity->time_in, 0, 5) : null" />
                <x-form.input name="time_out" label="Time Out" type="time" :value="$activity->time_out ? substr($activity->time_out, 0, 5) : null" />
                <x-form.input name="visited_by" label="Visited By" :value="$defaultVisitedBy" help="Record the actual investigator or authorized person who performed the visit." />
                <x-form.input name="person_met_contact" label="Person Met / Contact Details" :value="$activity->person_met_contact" maxlength="255" />
            </x-ui.form-section>

            <x-ui.form-section title="Findings and References" description="Keep remarks factual. Supporting evidence can be managed in Photos & Videos and linked to this activity.">
                <x-form.textarea name="remarks" label="Remarks" :value="$activity->remarks" class="sm:col-span-2" rows="7" />
                <x-form.textarea name="supporting_reference" label="Supporting Reference" :value="$activity->supporting_reference" class="sm:col-span-2" rows="4" help="Optional text reference, document identifier, or external reference supported by the current schema." />
            </x-ui.form-section>

            <x-ui.sticky-form-toolbar>
                <span>Activity status is internal; folder status remains automatic.</span>
                <x-slot:actions>
                    <button type="submit" name="intent" value="return" class="ui-button-secondary">Save and Return</button>
                    <button type="submit" name="intent" value="stay" class="ui-button-primary">Save Activity</button>
                </x-slot:actions>
            </x-ui.sticky-form-toolbar>
        </form>

        <aside class="min-w-0 space-y-6" aria-label="Activity notes and media references">
            <section class="ui-panel p-5 sm:p-6" aria-labelledby="notes-title">
                <h2 id="notes-title" class="ui-section-title">Notes Timeline</h2>
                <div class="mt-5">
                    <x-ui.note-timeline :notes="$activity->notes->map(fn ($note) => [
                        'author' => $note->author->full_name,
                        'date' => $note->created_at->timezone(config('cims.display_timezone'))->format('M j, Y g:i A'),
                        'text' => $note->note.($note->follow_up_needed ? ' — Follow-up needed' : ''),
                    ])->all()" />
                </div>
                <form method="POST" action="{{ route('client-folders.activities.notes.store', [$clientFolder, $activity]) }}" class="mt-6 border-t border-ui-border pt-5">
                    @csrf
                    <x-form.textarea name="note" label="Add Note" rows="4" required help="Notes are append-only and retain their author and timestamp." />
                    <label class="mt-3 flex min-h-11 cursor-pointer items-center gap-3 text-sm font-semibold">
                        <input type="hidden" name="follow_up_needed" value="0">
                        <input type="checkbox" name="follow_up_needed" value="1" @checked(old('follow_up_needed')) class="size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary">
                        Follow-up needed
                    </label>
                    <button type="submit" class="ui-button-primary mt-4 w-full sm:w-auto">Add Note</button>
                </form>
            </section>

            <section class="ui-panel p-5 sm:p-6" aria-labelledby="media-title">
                <div class="flex items-center justify-between gap-3"><h2 id="media-title" class="ui-section-title">Supporting Media</h2><span class="text-sm font-bold text-text-muted">{{ $activity->media_references_count }}</span></div>
                @if($activity->mediaReferences->isEmpty())
                    <div class="mt-4 rounded-control bg-surface-subtle p-4 text-sm leading-6 text-text-muted">
                        <p>No supporting media references are linked.</p>
                        <a href="{{ route('client-folders.media.index', $clientFolder) }}" class="mt-2 inline-flex font-semibold text-brand-primary hover:underline">Manage Photos &amp; Videos</a>
                    </div>
                @else
                    <ul class="mt-4 divide-y divide-ui-border overflow-hidden rounded-card border border-ui-border">
                        @foreach($activity->mediaReferences as $media)
                            <li class="p-3 text-sm"><p class="break-words font-semibold">{{ $media->pivot->label ?: $media->file_name }}</p><p class="mt-1 text-xs text-text-muted">{{ str($media->media_type->value)->title() }} · {{ str($media->category->value)->replace('_', ' ')->title() }}</p></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </aside>
    </div>
@endsection
