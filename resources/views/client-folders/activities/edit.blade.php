@extends('layouts.app')

@section('title', $activity->name)

@section('content')
    @php
        $personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null);
        $editActivityStatus = old('status', $activity->status->value);
        $editScheduleEnabled = in_array($editActivityStatus, [App\Enums\ActivityStatus::Scheduled->value, App\Enums\ActivityStatus::FollowUp->value], true);
        $editScheduleValue = $editScheduleEnabled
            ? old('scheduled_at', $activity->scheduled_at?->timezone(config('cims.display_timezone'))->format('Y-m-d'))
            : '';
        $editScheduleTimeValue = $editScheduleEnabled
            ? old('scheduled_time', $activity->scheduled_has_time ? $activity->scheduled_at?->timezone(config('cims.display_timezone'))->format('H:i') : '')
            : '';
    @endphp

    <x-ui.breadcrumb :items="[
        ['label' => 'Client Folders', 'url' => route('client-folders.index')],
        ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', [$clientFolder] + $personParams)],
        ['label' => 'CI Activities', 'url' => route('client-folders.activities.index', [$clientFolder] + $personParams)],
        ['label' => $activity->name],
    ]" />

    <x-ui.page-header :title="$activity->name">
        <x-slot:description>Update the activity, schedule the next action, and preserve an auditable history.</x-slot:description>
        <x-slot:actions><x-ui.status-badge :status="$activity->status" /><a href="{{ route('client-folders.activities.index', [$clientFolder] + $personParams) }}" class="ui-button-secondary">All Activities</a></x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(20rem,0.7fr)]">
        <form method="POST" action="{{ route('client-folders.activities.update', [$clientFolder, $activity]) }}" class="min-w-0 space-y-6" data-unsaved-form>
            @csrf
            @method('PUT')
            <input type="hidden" name="co_maker_id" value="{{ $activePerson->id ?? '' }}">
            <input type="hidden" name="expected_updated_at" value="{{ $activity->updated_at?->toISOString() }}">

            <div data-editing-presence data-editing-type="ci_activity" data-editing-id="{{ $activity->id }}" data-editing-label="Activity">
                <div data-editing-presence-banner hidden role="status" class="flex items-start gap-2 rounded-control border border-progress/30 bg-progress-soft p-3 text-sm text-progress"><x-ui.icon name="info" size="size-4" class="mt-0.5 shrink-0" /><span data-editing-presence-text></span></div>
            </div>
            <x-ui.record-meta :updated-by="$activity->updater?->full_name" :updated-at="$activity->updated_at" />

            <x-ui.form-section title="Activity Details" description="Schedule / Follow-up is available for Scheduled and For Follow-up statuses. A date is required for Scheduled activities; time is optional.">
                <div data-ci-edit-status><x-form.select name="status" label="Status" :options="collect($statuses)->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all()" :selected="$editActivityStatus" required /></div>
                <div id="schedule"><label for="scheduled_at" class="ui-label">Schedule / Follow-up Date</label><input id="scheduled_at" name="scheduled_at" type="date" value="{{ $editScheduleValue }}" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted disabled:opacity-75" data-ci-edit-schedule @disabled(! $editScheduleEnabled) aria-disabled="{{ $editScheduleEnabled ? 'false' : 'true' }}"><x-form.validation-message for="scheduled_at" /></div>
                <div><label for="scheduled_time" class="ui-label">Time <span class="font-normal text-text-muted">(optional)</span></label><input id="scheduled_time" name="scheduled_time" type="time" value="{{ $editScheduleTimeValue }}" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted disabled:opacity-75" data-ci-edit-schedule-time @disabled(! $editScheduleEnabled) aria-disabled="{{ $editScheduleEnabled ? 'false' : 'true' }}"><p class="ui-help" data-ci-edit-schedule-help>{{ $editScheduleEnabled ? 'Without a time, the creator is reminded at 8:00 AM on the selected date.' : 'Available when the status is Scheduled or For Follow-up.' }}</p><x-form.validation-message for="scheduled_time" /></div>
                <div class="space-y-1.5 rounded-control bg-surface-subtle px-3.5 py-3 text-xs leading-5 text-text-muted sm:col-span-2">
                    <p class="text-sm"><span class="font-semibold text-text-main">Creator:</span> {{ $activity->creator?->full_name ?? 'System-created' }} <span class="ml-1">(locked)</span></p>
                    <p>The original Creator remains unchanged. Scheduled and follow-up notifications will continue to be sent only to {{ $activity->creator?->full_name ?? 'the original Creator' }}. Other authorized CI users may still update or complete this activity.</p>
                    <p>Proof is optional and can be linked through Photos &amp; Videos after creation.</p>
                </div>
            </x-ui.form-section>

            <x-ui.form-section title="Remarks and Proof" description="Keep remarks concise. Proof remains optional and reuses Photos & Videos.">
                <x-form.textarea name="remarks" label="Short Remarks" :value="$activity->remarks" class="sm:col-span-2" rows="6" />
                <x-form.textarea name="supporting_reference" label="Supporting Reference" :value="$activity->supporting_reference" class="sm:col-span-2" rows="3" help="Optional document identifier or external reference." />
            </x-ui.form-section>

            <details class="ui-panel p-5 sm:p-6">
                <summary class="cursor-pointer text-sm font-bold text-brand-sidebar">Optional visit documentation</summary>
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <x-form.input name="visit_date" label="Visit Date" type="date" :value="$activity->visit_date?->format('Y-m-d')" />
                    <x-form.input name="visited_by" label="Visited By" :value="$activity->visited_by" help="Record the actual investigator or authorized person who performed the visit." />
                    <x-form.input name="time_in" label="Time In" type="time" :value="$activity->time_in ? substr($activity->time_in, 0, 5) : null" />
                    <x-form.input name="time_out" label="Time Out" type="time" :value="$activity->time_out ? substr($activity->time_out, 0, 5) : null" />
                    <x-form.input name="person_met_contact" label="Person Met / Contact Details" :value="$activity->person_met_contact" maxlength="255" class="sm:col-span-2" />
                </div>
            </details>

            <x-ui.sticky-form-toolbar>
                <span>Only the creator receives schedule reminders; every authorized CI may update the record.</span>
                <x-slot:actions><button type="submit" name="intent" value="return" class="ui-button-secondary">Save and Return</button><button type="submit" name="intent" value="stay" class="ui-button-primary">Save Activity</button></x-slot:actions>
            </x-ui.sticky-form-toolbar>
        </form>

        <aside class="min-w-0 space-y-6" aria-label="Activity notes and media references">
            <section class="ui-panel p-5 sm:p-6" aria-labelledby="notes-title">
                <h2 id="notes-title" class="ui-section-title">Notes Timeline</h2>
                <div class="mt-5"><x-ui.note-timeline :notes="$activity->notes->map(fn ($note) => ['author' => $note->author->full_name, 'date' => $note->created_at->timezone(config('cims.display_timezone'))->format('M j, Y g:i A'), 'text' => $note->note.($note->follow_up_needed ? ' — Follow-up needed' : '')])->all()" /></div>
                <form method="POST" action="{{ route('client-folders.activities.notes.store', [$clientFolder, $activity]) }}" class="mt-6 border-t border-ui-border pt-5">@csrf<x-form.textarea name="note" label="Add Note" rows="4" required help="Notes are append-only and retain their author and timestamp." /><label class="mt-3 flex min-h-11 cursor-pointer items-center gap-3 text-sm font-semibold"><input type="hidden" name="follow_up_needed" value="0"><input type="checkbox" name="follow_up_needed" value="1" @checked(old('follow_up_needed')) class="size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary">Follow-up needed</label><button type="submit" class="ui-button-primary mt-4 w-full sm:w-auto">Add Note</button></form>
            </section>

            <section class="ui-panel p-5 sm:p-6" aria-labelledby="media-title">
                <div class="flex items-center justify-between gap-3"><h2 id="media-title" class="ui-section-title">Supporting Proof</h2><span class="text-sm font-bold text-text-muted">{{ $activity->media_references_count }}</span></div>
                <x-form.validation-message for="attachment" />
                @if($activity->mediaReferences->isEmpty())
                    <div class="mt-4 rounded-control bg-surface-subtle p-4 text-sm leading-6 text-text-muted"><p>No proof is linked. Proof is optional.</p><a href="{{ route('client-folders.media.index', [$clientFolder] + $personParams) }}" class="mt-2 inline-flex font-semibold text-brand-primary hover:underline">Manage Photos &amp; Videos</a></div>
                @else
                    <ul class="mt-4 divide-y divide-ui-border overflow-hidden rounded-card border border-ui-border">
                        @foreach($activity->mediaReferences as $media)
                            <li class="p-3 text-sm">
                                <p class="break-words font-semibold">{{ $media->pivot->label ?: $media->file_name }}</p>
                                <p class="mt-1 text-xs text-text-muted">{{ str($media->media_type->value)->title() }} · {{ str($media->category->value)->replace('_', ' ')->title() }}</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <a href="{{ route('client-folders.activities.proof.content', [$clientFolder, $activity, $media]) }}" target="_blank" rel="noopener" class="ui-button-secondary-compact"><x-ui.icon name="eye" size="size-3.5" />Preview / Open</a>
                                    @if($activity->status === App\Enums\ActivityStatus::Completed)
                                        <form method="POST" action="{{ route('client-folders.activities.proof.replace', [$clientFolder, $activity, $media]) }}" enctype="multipart/form-data" data-ci-proof-replace-form>
                                            @csrf
                                            @method('PUT')
                                            <input id="replace-proof-{{ $media->id }}" name="attachment" type="file" accept="image/jpeg,image/png,image/webp,video/mp4" class="sr-only" data-ci-proof-replace-input>
                                            <label for="replace-proof-{{ $media->id }}" class="ui-button-secondary-compact cursor-pointer" data-ci-proof-replace-label><x-ui.icon name="upload" size="size-3.5" />Replace</label>
                                        </form>
                                    @else
                                        <span class="ui-button-secondary-compact cursor-not-allowed opacity-50" aria-disabled="true" title="Proof can only be replaced while the activity is Completed"><x-ui.icon name="upload" size="size-3.5" />Replace</span>
                                    @endif
                                    <button type="button" class="ui-button-danger-compact" data-modal-open="remove-proof-{{ $media->id }}"><x-ui.icon name="trash" size="size-3.5" />Remove</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    @foreach($activity->mediaReferences as $media)
                        <x-ui.confirmation-dialog id="remove-proof-{{ $media->id }}" title="Remove Proof Attachment?" :action="route('client-folders.activities.proof.destroy', [$clientFolder, $activity, $media])" method="DELETE" confirm-label="Remove Attachment" destructive>
                            <p><span class="font-semibold text-text-main">{{ $media->file_name }}</span> will be removed from this activity. If it is not referenced elsewhere, its stored file will also be retired.</p>
                        </x-ui.confirmation-dialog>
                    @endforeach
                @endif
            </section>
        </aside>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const status = document.querySelector('[data-ci-edit-status] select[name="status"]');
            const schedule = document.querySelector('[data-ci-edit-schedule]');
            const scheduleTime = document.querySelector('[data-ci-edit-schedule-time]');
            const help = document.querySelector('[data-ci-edit-schedule-help]');
            if (!(status instanceof HTMLSelectElement) || !(schedule instanceof HTMLInputElement) || !(scheduleTime instanceof HTMLInputElement)) return;

            const syncScheduleAvailability = () => {
                const enabled = ['scheduled', 'follow_up'].includes(status.value);
                if (!enabled) {
                    schedule.value = '';
                    scheduleTime.value = '';
                }
                schedule.disabled = !enabled;
                scheduleTime.disabled = !enabled;
                schedule.required = status.value === 'scheduled';
                schedule.setAttribute('aria-disabled', enabled ? 'false' : 'true');
                scheduleTime.setAttribute('aria-disabled', enabled ? 'false' : 'true');
                if (help) help.textContent = enabled
                    ? 'Without a time, the creator is reminded at 8:00 AM on the selected date.'
                    : 'Available when the status is Scheduled or For Follow-up.';
            };

            status.addEventListener('change', syncScheduleAvailability);
            syncScheduleAvailability();

            document.querySelectorAll('[data-ci-proof-replace-input]').forEach((input) => {
                input.addEventListener('change', () => {
                    if (!(input instanceof HTMLInputElement) || !input.files?.length) return;
                    const form = input.closest('[data-ci-proof-replace-form]');
                    const label = form?.querySelector('[data-ci-proof-replace-label]');
                    if (label) {
                        label.classList.add('pointer-events-none', 'opacity-60');
                        label.setAttribute('aria-disabled', 'true');
                    }
                    if (form instanceof HTMLFormElement) form.requestSubmit();
                });
            });
        });
    </script>
@endsection
