@extends('layouts.app')

@section('title', 'CI Activities')

@section('content')
    @php
        $personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null);
        $tabs = ['pending' => 'Pending', 'scheduled_today' => 'Scheduled Today', 'follow_up' => 'For Follow-up', 'completed' => 'Completed', 'all' => 'All Activities'];
        $addingNewActivityType = (bool) old('create_new_activity_type');
    @endphp

    <x-ui.breadcrumb :items="[
        ['label' => 'Client Folders', 'url' => route('client-folders.index')],
        ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', [$clientFolder] + $personParams)],
        ['label' => 'CI Activities'],
    ]" />

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <section class="ui-panel min-w-0 p-4 sm:p-5 lg:p-6" aria-labelledby="ci-activities-title">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex min-w-0 items-start gap-3"><span class="mt-0.5 text-brand-primary"><x-ui.icon name="activity" size="size-6" /></span><div><h2 id="ci-activities-title" class="text-xl font-bold tracking-tight text-brand-sidebar sm:text-2xl">CI Activities</h2><p class="mt-1 max-w-3xl text-sm leading-6 text-text-muted">Track pending, scheduled, follow-up, and completed investigation activities with proof of submission.</p></div></div>
                <button type="button" class="ui-button-primary shrink-0" data-ci-activity-dialog-open><x-ui.icon name="plus" size="size-4" />Add Activity</button>
            </div>

            <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <nav class="flex min-w-0 flex-1 gap-1 overflow-x-auto overflow-y-hidden border-b border-ui-border" aria-label="Activity status filters">
                    @foreach($tabs as $key => $label)
                        <a href="{{ route('client-folders.activities.index', [$clientFolder] + $personParams + ['status' => $key]) }}" @class(['relative flex min-h-10 shrink-0 items-center gap-2 rounded-t-control px-3 py-2 text-sm font-semibold transition', 'bg-brand-soft text-brand-primary after:absolute after:inset-x-0 after:-bottom-px after:h-0.5 after:bg-brand-primary' => $filter === $key, 'text-text-muted hover:bg-surface-muted hover:text-brand-sidebar' => $filter !== $key]) aria-current="{{ $filter === $key ? 'page' : 'false' }}">
                            {{ $label }} @if($counts[$key] > 0)<span class="rounded-full bg-surface px-2 py-0.5 text-xs font-bold shadow-sm">{{ $counts[$key] }}</span>@endif
                        </a>
                    @endforeach
                </nav>
                <button type="button" class="ui-button-danger shrink-0 !min-h-9 !px-3 text-sm" data-modal-open="bulk-delete-activities" data-bulk-delete-button disabled>
                    <x-ui.icon name="trash" size="size-4" /><span data-bulk-delete-label>Delete Selected</span>
                </button>
            </div>

            <div class="mt-4 overflow-x-auto overflow-y-hidden rounded-card border border-ui-border">
                <table class="w-full min-w-[71rem] text-left text-sm">
                    <thead class="bg-surface-subtle text-xs font-bold text-text-muted"><tr><th scope="col" class="w-10 px-3 py-3 text-center"><input type="checkbox" class="size-4 rounded border-ui-border text-brand-primary focus:ring-brand-primary" aria-label="Select all visible activities" data-ci-select-all></th><th scope="col" class="px-4 py-3">Activity</th><th scope="col" class="px-3 py-3">Status</th><th scope="col" class="px-3 py-3">Schedule</th><th scope="col" class="px-3 py-3">Proof / Submission</th><th scope="col" class="px-3 py-3">Creator</th><th scope="col" class="px-3 py-3">Last Updated</th><th scope="col" class="px-3 py-3 text-center">Actions</th></tr></thead>
                    <tbody class="divide-y divide-ui-border bg-surface">
                        @forelse($activities as $activity)
                            <tr class="align-middle transition hover:bg-surface-subtle/70">
                                <td class="px-3 py-3 text-center"><input type="checkbox" value="{{ $activity->id }}" class="size-4 rounded border-ui-border text-brand-primary focus:ring-brand-primary" aria-label="Select {{ $activity->name }}" data-ci-activity-select></td>
                                <td class="px-4 py-3"><div class="flex items-start gap-2.5"><span class="grid size-8 shrink-0 place-items-center rounded-full border border-ui-border bg-surface-subtle text-text-muted"><x-ui.icon name="report" size="size-4" /></span><div class="min-w-0"><p class="font-bold text-text-main">{{ $activity->name }}</p><p class="mt-0.5 max-w-52 truncate text-xs text-text-muted">{{ $activity->target ?: ($activity->definition?->is_required ? 'Required investigation activity' : 'General investigation activity') }}</p></div></div></td>
                                <td class="px-3 py-3"><span @class(['inline-flex rounded-full px-2.5 py-1 text-xs font-bold', 'bg-progress-soft text-progress' => $activity->status === App\Enums\ActivityStatus::Pending, 'bg-brand-soft text-brand-primary' => $activity->status === App\Enums\ActivityStatus::Scheduled, 'bg-[#fff0e7] text-[#c85b12]' => $activity->status === App\Enums\ActivityStatus::FollowUp, 'bg-success-soft text-success' => $activity->status === App\Enums\ActivityStatus::Completed])>{{ $activity->status->label() }}</span></td>
                                <td class="px-3 py-3 text-xs leading-5 text-text-muted">@if($activity->scheduled_at)<span class="block font-semibold text-text-main">{{ $activity->scheduled_at->timezone(config('cims.display_timezone'))->format('M j, Y') }}</span>{{ $activity->scheduled_at->timezone(config('cims.display_timezone'))->format('g:i A') }}@elseif($activity->visit_date)<span class="block font-semibold text-text-main">{{ $activity->visit_date->format('M j, Y') }}</span>Completed visit @else — @endif</td>
                                <td class="px-3 py-3">@if($activity->media_references_count > 0)<span class="inline-flex items-center gap-1.5 text-xs font-semibold text-success"><x-ui.icon name="attachment" size="size-4" />With proof</span>@elseif($activity->supporting_reference)<span class="inline-flex items-center gap-1.5 text-xs font-semibold text-success"><x-ui.icon name="check-circle" size="size-4" />Submitted</span>@else<span class="inline-flex items-center gap-1.5 text-xs font-semibold text-text-muted"><x-ui.icon name="clock" size="size-4" />No proof</span>@endif</td>
                                <td class="px-3 py-3 text-xs leading-5"><span class="block max-w-36 truncate font-semibold text-text-main">{{ $activity->creator?->full_name ?? 'System-created' }}</span><span class="text-text-muted">Locked creator</span></td>
                                <td class="px-3 py-3 text-xs leading-5 text-text-muted"><span class="block font-semibold text-text-main">{{ $activity->updated_at->timezone(config('cims.display_timezone'))->format('M j, Y') }}</span>{{ $activity->updated_at->timezone(config('cims.display_timezone'))->format('g:i A') }}@if($activity->updater) · {{ $activity->updater->full_name }}@endif</td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <a href="{{ route('client-folders.activities.edit', [$clientFolder, $activity] + $personParams) }}" class="ui-button-secondary-compact !size-8 !min-h-8 !px-0" aria-label="View {{ $activity->name }}" title="View"><x-ui.icon name="eye" size="size-4" /></a>
                                        <a href="{{ route('client-folders.activities.edit', [$clientFolder, $activity] + $personParams) }}" class="ui-button-secondary-compact !size-8 !min-h-8 !px-0" aria-label="Update {{ $activity->name }}" title="Update"><x-ui.icon name="edit" size="size-4" /></a>
                                        <a href="{{ route('client-folders.activities.edit', [$clientFolder, $activity] + $personParams) }}#schedule" class="ui-button-secondary-compact !size-8 !min-h-8 !px-0" aria-label="Schedule or reschedule {{ $activity->name }}" title="Schedule / Reschedule"><x-ui.icon name="calendar" size="size-4" /></a>
                                        @if($activity->status !== App\Enums\ActivityStatus::Completed)<form method="POST" action="{{ route('client-folders.activities.update', [$clientFolder, $activity]) }}">@csrf @method('PUT')<input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}"><input type="hidden" name="expected_updated_at" value="{{ $activity->updated_at->toISOString() }}"><input type="hidden" name="status" value="completed"><input type="hidden" name="intent" value="return"><button type="submit" class="ui-button-secondary-compact !size-8 !min-h-8 !px-0" aria-label="Complete {{ $activity->name }}" title="Complete"><x-ui.icon name="check-circle" size="size-4" /></button></form>@endif
                                        <x-ui.context-menu :label="$activity->name.' more actions'">
                                            <x-slot:trigger><span class="ui-dots-trigger !size-8"><x-ui.icon name="more-vertical" size="size-4" /></span></x-slot:trigger>
                                            <a href="{{ route('client-folders.activities.edit', [$clientFolder, $activity] + $personParams) }}#notes-title" role="menuitem" class="client-folder-menu-item">View notes</a>
                                            <a href="{{ route('client-folders.media.index', [$clientFolder] + $personParams) }}" role="menuitem" class="client-folder-menu-item">Manage proof</a>
                                            @if($activity->status === App\Enums\ActivityStatus::Completed)
                                                <button type="button" role="menuitem" class="client-folder-menu-item" data-modal-open="reopen-activity-{{ $activity->id }}">Reopen Activity</button>
                                            @endif
                                            <button type="button" role="menuitem" class="client-folder-menu-item text-danger" data-modal-open="delete-activity-{{ $activity->id }}">Delete Activity</button>
                                        </x-ui.context-menu>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-6 py-12 text-center">@if($counts['all'] === 0)<p class="font-semibold text-text-main">No CI activities yet.</p><p class="mt-1 text-sm text-text-muted">Add an activity when there is something to process, schedule, follow up, or document.</p><button type="button" class="ui-button-primary mx-auto mt-4" data-ci-activity-dialog-open><x-ui.icon name="plus" size="size-4" />Add Activity</button>@else<p class="font-semibold text-text-main">No activities in this view.</p><p class="mt-1 text-sm text-text-muted">Choose another status or add an activity.</p>@endif</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @foreach($activities as $activity)
                @if($activity->status === App\Enums\ActivityStatus::Completed)
                    <x-ui.confirmation-dialog id="reopen-activity-{{ $activity->id }}" title="Reopen Activity?" :action="route('client-folders.activities.update', [$clientFolder, $activity])" method="PUT" confirm-label="Reopen Activity">
                        <p>This activity will be returned to Pending. The previous completion will remain visible in Activity History.</p>
                        <x-slot:formFields>
                            <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
                            <input type="hidden" name="expected_updated_at" value="{{ $activity->updated_at->toISOString() }}">
                            <input type="hidden" name="status" value="pending">
                            <input type="hidden" name="intent" value="return">
                        </x-slot:formFields>
                    </x-ui.confirmation-dialog>
                @endif
                <x-ui.confirmation-dialog id="delete-activity-{{ $activity->id }}" title="Permanently Delete Activity?" :action="route('client-folders.activities.destroy', [$clientFolder, $activity])" method="DELETE" confirm-label="Permanently Delete" destructive>
                    <p>This activity will be permanently deleted and cannot be restored. Continue only if this activity was created by mistake or is no longer needed.</p>
                    <x-slot:formFields><input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}"></x-slot:formFields>
                </x-ui.confirmation-dialog>
            @endforeach

            <x-ui.modal id="bulk-delete-activities" title="Permanently Delete Selected Activities?">
                <p>The selected activities will be permanently deleted and cannot be restored.</p>
                <p class="mt-3 font-semibold text-text-main" data-bulk-delete-summary>0 activities selected</p>
                <x-slot:footer>
                    <button type="button" data-modal-close class="ui-button-secondary">Cancel</button>
                    <form method="POST" action="{{ route('client-folders.activities.bulk-destroy', $clientFolder) }}">
                        @csrf @method('DELETE')
                        <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
                        <input type="hidden" name="status" value="{{ $filter }}">
                        <span data-bulk-delete-inputs></span>
                        <button class="ui-button-danger">Permanently Delete</button>
                    </form>
                </x-slot:footer>
            </x-ui.modal>

            <div class="mt-4 space-y-3"><div class="flex items-start gap-2 rounded-control border border-brand-primary/20 bg-brand-soft/60 px-4 py-3 text-sm text-brand-primary"><x-ui.icon name="info" size="size-4" class="mt-0.5" /><p>Activities remain visible after scheduling, submission, or completion for traceability and audit purposes.</p></div><div class="flex items-start gap-2 rounded-control border border-progress/20 bg-progress-soft/70 px-4 py-3 text-sm text-[#76520c]"><x-ui.icon name="warning" size="size-4" class="mt-0.5" /><p>Only the activity creator receives scheduled notifications. Other authorized CI users may view and update the activity as needed.</p></div></div>
        </section>

        <aside class="ui-panel min-w-0 p-5" aria-labelledby="activity-history-title">
            <div class="flex items-center gap-2 text-brand-primary"><x-ui.icon name="clock" size="size-5" /><h2 id="activity-history-title" class="text-base font-bold text-brand-sidebar">Activity History</h2></div>
            @if($history->isEmpty())<div class="mt-6 rounded-control bg-surface-subtle p-4 text-sm leading-6 text-text-muted">No activity history has been recorded for this person yet.</div>@else
                <ol class="relative mt-6 space-y-0">@foreach($history as $event)<li class="relative grid grid-cols-[1rem_1fr] gap-3 pb-6 last:pb-0">@unless($loop->last)<span class="absolute bottom-0 left-[0.4375rem] top-4 border-l border-dashed border-ui-border-strong" aria-hidden="true"></span>@endunless<span @class(['relative z-10 mt-1 size-3.5 rounded-full border-2 border-white shadow-sm', 'bg-success' => $event->tone === 'success', 'bg-brand-primary' => $event->tone === 'progress', 'bg-text-muted' => $event->tone === 'neutral'])></span><div class="min-w-0"><p class="text-sm font-bold leading-5 text-text-main">{{ $event->label }}</p>@if($event->detail)<p class="mt-1 break-words text-xs leading-5 text-text-muted">{{ $event->detail }}</p>@endif<p class="mt-1 text-xs leading-5 text-text-muted">by {{ $event->user?->full_name ?? 'System' }}<br>{{ $event->created_at->timezone(config('cims.display_timezone'))->format('M j, Y · g:i A') }}</p></div></li>@endforeach</ol>
            @endif
        </aside>
    </div>

    <dialog class="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-xl overflow-y-auto overscroll-contain rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45" data-ci-activity-dialog @if($errors->any()) open @endif>
        <form method="POST" action="{{ route('client-folders.activities.store', $clientFolder) }}" class="p-5 sm:p-6">@csrf<input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
            <div class="flex items-start justify-between gap-4"><div><h2 class="text-xl font-bold text-brand-sidebar">Add Activity</h2><p class="mt-1 text-sm text-text-muted">Create a focused activity for {{ $activePerson?->full_name ?? $clientFolder->display_name }}.</p></div><button type="button" class="ui-icon-button -mr-2 -mt-2" data-ci-activity-dialog-close aria-label="Close"><x-ui.icon name="close" size="size-5" /></button></div>
            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="activity-definition" class="ui-label">Activity Type</label>
                    <select id="activity-definition" name="activity_definition_id" class="ui-control" required data-activity-type-select>
                        <option value="">Select activity type</option>
                        @foreach($definitions as $definition)
                            <option value="{{ $definition->id }}" @selected(! $addingNewActivityType && (string) old('activity_definition_id') === (string) $definition->id)>{{ $definition->name }}</option>
                        @endforeach
                        <option value="{{ App\Models\ActivityDefinition::NEW_TYPE_VALUE }}" @selected($addingNewActivityType)>+ Add New Activity Type</option>
                    </select>
                    <x-form.validation-message for="activity_definition_id" />
                </div>
                <div class="sm:col-span-2" data-new-activity-type-fields @if(! $addingNewActivityType) hidden @endif>
                    <label for="new-activity-type" class="ui-label">New Activity Type</label>
                    <input id="new-activity-type" name="new_activity_type" value="{{ old('new_activity_type') }}" class="ui-control" maxlength="255" autocomplete="off" placeholder="e.g. Barangay Certification Follow-up" @if($addingNewActivityType) required @endif data-new-activity-type-input>
                    <p class="ui-help">This reusable type will be available for future CI activities.</p>
                    <x-form.validation-message for="new_activity_type" />
                </div>
                <div class="sm:col-span-2"><label for="activity-target" class="ui-label">Bank / Office / Target <span class="font-normal text-text-muted">(optional)</span></label><input id="activity-target" name="target" value="{{ old('target') }}" class="ui-control" maxlength="255" placeholder="e.g. BINHI Rural Bank – Main"><x-form.validation-message for="target" /></div>
                <div><label for="activity-status" class="ui-label">Status</label><select id="activity-status" name="status" class="ui-control" required><option value="pending" @selected(old('status', 'pending') === 'pending')>Pending</option><option value="scheduled" @selected(old('status') === 'scheduled')>Scheduled</option><option value="follow_up" @selected(old('status') === 'follow_up')>For Follow-up</option><option value="completed" @selected(old('status') === 'completed')>Completed</option></select><x-form.validation-message for="status" /></div>
                <div><label for="activity-schedule" class="ui-label">Schedule / Follow-up</label><input id="activity-schedule" name="scheduled_at" type="datetime-local" value="{{ old('scheduled_at') }}" class="ui-control"><x-form.validation-message for="scheduled_at" /></div>
                <div class="sm:col-span-2"><label for="activity-remarks" class="ui-label">Short Remarks <span class="font-normal text-text-muted">(optional)</span></label><textarea id="activity-remarks" name="remarks" rows="3" class="ui-control" placeholder="Add concise operational details.">{{ old('remarks') }}</textarea><x-form.validation-message for="remarks" /></div>
                <div class="sm:col-span-2 rounded-control bg-surface-subtle p-3 text-sm text-text-muted"><span class="font-semibold text-text-main">Creator:</span> {{ request()->user()->full_name }} <span class="ml-1">(locked)</span><br><span class="text-xs">Proof is optional and can be linked through Photos &amp; Videos after creation.</span></div>
            </div>
            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><button type="button" class="ui-button-secondary" data-ci-activity-dialog-close>Cancel</button><button type="submit" class="ui-button-primary"><x-ui.icon name="plus" size="size-4" />Add Activity</button></div>
        </form>
    </dialog>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const select = document.querySelector('[data-activity-type-select]');
            const fields = document.querySelector('[data-new-activity-type-fields]');
            const input = document.querySelector('[data-new-activity-type-input]');
            if (!(select instanceof HTMLSelectElement) || !(fields instanceof HTMLElement) || !(input instanceof HTMLInputElement)) return;

            const syncNewActivityType = () => {
                const addingNewType = select.value === @js(App\Models\ActivityDefinition::NEW_TYPE_VALUE);
                fields.hidden = ! addingNewType;
                input.required = addingNewType;
            };

            select.addEventListener('change', syncNewActivityType);
            syncNewActivityType();
        });

        document.addEventListener('DOMContentLoaded', () => {
            const selectAll = document.querySelector('[data-ci-select-all]');
            const selections = [...document.querySelectorAll('[data-ci-activity-select]')];
            const bulkButton = document.querySelector('[data-bulk-delete-button]');
            const bulkLabel = document.querySelector('[data-bulk-delete-label]');
            const bulkSummary = document.querySelector('[data-bulk-delete-summary]');
            const bulkInputs = document.querySelector('[data-bulk-delete-inputs]');
            if (!(selectAll instanceof HTMLInputElement) || !(bulkButton instanceof HTMLButtonElement) || !bulkLabel || !bulkSummary || !bulkInputs) return;

            const syncBulkSelection = () => {
                const selected = selections.filter((checkbox) => checkbox instanceof HTMLInputElement && checkbox.checked);
                selectAll.disabled = selections.length === 0;
                selectAll.checked = selections.length > 0 && selected.length === selections.length;
                selectAll.indeterminate = selected.length > 0 && selected.length < selections.length;
                bulkButton.disabled = selected.length === 0;
                bulkLabel.textContent = selected.length > 0 ? `Delete Selected (${selected.length})` : 'Delete Selected';
                bulkSummary.textContent = `${selected.length} ${selected.length === 1 ? 'activity' : 'activities'} selected`;
                bulkInputs.replaceChildren(...selected.map((checkbox) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'activity_ids[]';
                    input.value = checkbox.value;
                    return input;
                }));
            };

            selectAll.addEventListener('change', () => {
                selections.forEach((checkbox) => {
                    if (checkbox instanceof HTMLInputElement) checkbox.checked = selectAll.checked;
                });
                syncBulkSelection();
            });
            selections.forEach((checkbox) => checkbox.addEventListener('change', syncBulkSelection));
            syncBulkSelection();
        });
    </script>
@endsection
