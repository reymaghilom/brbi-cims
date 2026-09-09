@extends('layouts.app')

@section('title', $activity->display_name)

@section('content')
    @php
        $personParams = App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null);
        $localSchedule = $activity->scheduled_at?->timezone(config('cims.display_timezone'));
        $supportsSchedule = in_array($activity->status, [App\Enums\ActivityStatus::Scheduled, App\Enums\ActivityStatus::FollowUp], true);
        $contextLabel = $activePerson ? 'Co-Maker: '.$activePerson->full_name : 'Applicant: '.$clientFolder->display_name;
    @endphp

    <x-ui.breadcrumb :items="[
        ['label' => 'Client Folders', 'url' => route('client-folders.index')],
        ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', [$clientFolder] + $personParams)],
        ['label' => 'CI Activities', 'url' => route('client-folders.activities.index', [$clientFolder] + $personParams)],
        ['label' => $activity->display_name],
    ]" />

    <x-ui.page-header :title="$activity->display_name">
        <x-slot:description>{{ $contextLabel }}</x-slot:description>
        <x-slot:actions><a href="{{ route('client-folders.activities.index', [$clientFolder] + $personParams) }}" class="ui-button-secondary">All Activities</a></x-slot:actions>
    </x-ui.page-header>

    <div
        data-default-check-modal-source
        data-default-check-activity-id="{{ $activity->id }}"
        data-default-check-context="{{ $contextLabel }}"
        data-default-check-status="{{ $activity->status->value }}"
        data-default-check-status-label="{{ $activity->status->label() }}"
        data-default-check-remarks="{{ $activity->remarks }}"
        data-default-check-schedule="{{ $localSchedule?->format('M j, Y') ?? '—' }}"
        data-default-check-schedule-time="{{ $localSchedule ? ($activity->scheduled_has_time ? $localSchedule->format('g:i A') : 'No specific time') : '' }}"
        data-default-check-sort-schedule="{{ $activity->scheduled_at?->timestamp ?? 0 }}"
        data-default-check-updated-date="{{ $activity->updated_at->timezone(config('cims.display_timezone'))->format('M j, Y') }}"
        data-default-check-updated-detail="{{ $activity->updated_at->timezone(config('cims.display_timezone'))->format('g:i A') }}{{ $activity->updater ? ' · '.$activity->updater->full_name : '' }}"
        data-default-check-updated-timestamp="{{ $activity->updated_at->timestamp }}"
        data-default-check-updated-iso="{{ $activity->updated_at->toISOString() }}"
    >
        <section class="ui-panel mx-auto max-w-3xl overflow-hidden" aria-labelledby="default-check-title">
            <div class="flex flex-col gap-3 border-b border-ui-border px-5 py-4 sm:flex-row sm:items-start sm:justify-between sm:px-6">
                <div class="min-w-0">
                    <h2 id="default-check-title" class="flex items-start gap-2 text-lg font-bold text-brand-sidebar"><x-ui.icon name="edit" size="size-5" class="mt-0.5 shrink-0 text-brand-primary" /><span class="min-w-0 break-words">Edit {{ $activity->display_name }}</span></h2>
                    <p class="mt-1 break-words text-sm text-text-muted">{{ $contextLabel }}</p>
                </div>
            </div>

            <form method="POST" action="{{ route('client-folders.activities.update', [$clientFolder, $activity]) }}" class="p-5 sm:p-6" data-default-check-form data-current-status="{{ $activity->status->value }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
                <input type="hidden" name="expected_updated_at" value="{{ $activity->updated_at->toISOString() }}">
                <input type="hidden" name="intent" value="return">

                <div class="mb-4 rounded-control border border-danger/25 bg-danger-soft px-3.5 py-3 text-sm text-danger" data-default-check-errors hidden></div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="default-check-status-{{ $activity->id }}" class="ui-label">Status</label>
                        <select id="default-check-status-{{ $activity->id }}" name="status" class="ui-control" required data-default-check-status-control>
                            @foreach($statuses as $status)
                                <option value="{{ $status->value }}" @selected($activity->status === $status)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2 grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(8rem,0.55fr)]">
                        <div><label for="default-check-date-{{ $activity->id }}" class="ui-label">Schedule / Follow-up Date <span class="font-normal text-text-muted">(optional)</span></label><input id="default-check-date-{{ $activity->id }}" name="scheduled_at" type="date" value="{{ $supportsSchedule ? $localSchedule?->format('Y-m-d') : '' }}" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-default-check-date @disabled(! $supportsSchedule)></div>
                        <div><label for="default-check-time-{{ $activity->id }}" class="ui-label">Time <span class="font-normal text-text-muted">(optional)</span></label><input id="default-check-time-{{ $activity->id }}" name="scheduled_time" type="time" value="{{ $supportsSchedule && $activity->scheduled_has_time ? $localSchedule?->format('H:i') : '' }}" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-default-check-time @disabled(! $supportsSchedule || ! $localSchedule)></div>
                        <p class="text-xs leading-5 text-text-muted sm:col-span-2">Date and time are optional. Select a date to enable a specific time.</p>
                    </div>
                    <div class="sm:col-span-2"><label for="default-check-remarks-{{ $activity->id }}" class="ui-label">Short Remarks <span class="font-normal text-text-muted">(optional)</span></label><textarea id="default-check-remarks-{{ $activity->id }}" name="remarks" rows="4" class="ui-control" data-default-check-remarks>{{ $activity->remarks }}</textarea></div>
                </div>

                <div class="mt-5 flex flex-col-reverse items-stretch gap-2.5 border-t border-ui-border pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm font-semibold text-success" data-default-check-success role="status" aria-live="polite" hidden>✓ Changes saved successfully.</p>
                    <p class="flex items-start gap-1.5 rounded-control border border-progress/30 bg-progress-soft px-3 py-2 text-sm font-semibold text-progress" data-default-check-no-changes role="status" aria-live="polite" hidden><x-ui.icon name="info" size="size-4" class="mt-0.5 shrink-0" aria-hidden="true" />No changes detected. Nothing needs to be updated.</p>
                    <div class="flex flex-col-reverse gap-2.5 sm:ml-auto sm:flex-row">
                        <button type="button" class="ui-button-secondary w-full sm:w-auto" data-default-check-cancel><x-ui.icon name="close" size="size-4" />Cancel</button>
                        <button type="submit" class="ui-button-primary w-full sm:w-auto" data-default-check-submit><x-ui.icon name="check" size="size-4" />Save Changes</button>
                    </div>
                </div>
            </form>
        </section>

        <dialog class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45" data-default-check-completion aria-labelledby="default-check-completion-title-{{ $activity->id }}">
            <div class="border-b border-ui-border px-5 py-4"><h2 id="default-check-completion-title-{{ $activity->id }}" class="flex items-center gap-2 text-lg font-bold text-brand-sidebar"><x-ui.icon name="check-circle" size="size-5" class="shrink-0 text-success" /><span>Mark {{ $activity->display_name }} as completed?</span></h2></div>
            <div class="px-5 py-5 text-sm leading-6 text-text-muted">The schedule and time will be cleared. Completion will be recorded in Recent Activity under the actual user confirming this action.</div>
            <div class="flex flex-col-reverse gap-2.5 border-t border-ui-border px-5 py-4 sm:flex-row sm:justify-end"><button type="button" class="ui-button-secondary" data-default-check-completion-cancel><x-ui.icon name="close" size="size-4" />Cancel</button><button type="button" class="ui-button-primary" data-default-check-completion-confirm><x-ui.icon name="check" size="size-4" />Mark Completed</button></div>
        </dialog>

        <dialog class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45" data-default-check-discard-confirm aria-labelledby="default-check-discard-title-{{ $activity->id }}">
            <div class="border-b border-ui-border px-5 py-4"><h2 id="default-check-discard-title-{{ $activity->id }}" class="text-lg font-bold text-brand-sidebar">Discard unsaved changes?</h2></div>
            <div class="px-5 py-5 text-sm leading-6 text-text-muted">Your changes have not been saved.</div>
            <div class="flex flex-col-reverse gap-2.5 border-t border-ui-border px-5 py-4 sm:flex-row sm:justify-end"><button type="button" class="ui-button-secondary" data-default-check-discard-keep>Keep Editing</button><button type="button" class="ui-button-danger" data-default-check-discard-confirm-button>Discard Changes</button></div>
        </dialog>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const source = document.querySelector('[data-default-check-modal-source]');
            const form = source?.querySelector('[data-default-check-form]');
            if (!(source instanceof HTMLElement) || !(form instanceof HTMLFormElement)) return;
            const status = form.querySelector('[data-default-check-status-control]');
            const date = form.querySelector('[data-default-check-date]');
            const time = form.querySelector('[data-default-check-time]');
            const remarks = form.querySelector('[data-default-check-remarks]');
            const completion = source.querySelector('[data-default-check-completion]');
            const discard = source.querySelector('[data-default-check-discard-confirm]');
            if (!(status instanceof HTMLSelectElement) || !(date instanceof HTMLInputElement) || !(time instanceof HTMLInputElement)) return;
            const sync = () => { const enabled = ['scheduled', 'follow_up'].includes(status.value); if (! enabled) { date.value = ''; time.value = ''; } date.disabled = ! enabled; if (! enabled || date.value === '') time.value = ''; time.disabled = ! enabled || date.value === ''; };
            status.addEventListener('change', sync); date.addEventListener('input', sync); sync();

            const readValues = () => ({
                status: status.value,
                scheduledAt: date.disabled ? '' : (date.value ?? ''),
                scheduledTime: time.disabled ? '' : (time.value ?? ''),
                remarks: (remarks instanceof HTMLTextAreaElement ? remarks.value : '').trim(),
            });
            const baseline = readValues();
            const isDirty = () => { const current = readValues(); return Object.keys(baseline).some((key) => baseline[key] !== current[key]); };

            const hideNoChangesMessage = () => {
                const noChanges = form.querySelector('[data-default-check-no-changes]');
                if (noChanges instanceof HTMLElement) noChanges.hidden = true;
            };
            form.addEventListener('input', hideNoChangesMessage);
            form.addEventListener('change', hideNoChangesMessage);

            form.addEventListener('submit', (event) => {
                if (! isDirty()) {
                    event.preventDefault();
                    const noChanges = form.querySelector('[data-default-check-no-changes]');
                    if (noChanges instanceof HTMLElement) noChanges.hidden = false;
                    return;
                }
                if (status.value === 'completed' && form.dataset.currentStatus !== 'completed' && form.dataset.completionConfirmed !== 'true' && completion instanceof HTMLDialogElement) {
                    event.preventDefault();
                    completion.showModal();
                }
            });
            source.querySelector('[data-default-check-completion-cancel]')?.addEventListener('click', () => completion?.close());
            source.querySelector('[data-default-check-completion-confirm]')?.addEventListener('click', () => { if (completion instanceof HTMLDialogElement) completion.close(); form.dataset.completionConfirmed = 'true'; form.requestSubmit(); });

            const goToIndex = () => window.location.assign(@js(route('client-folders.activities.index', [$clientFolder] + $personParams)));
            source.querySelector('[data-default-check-cancel]')?.addEventListener('click', () => {
                if (isDirty() && discard instanceof HTMLDialogElement) { discard.showModal(); return; }
                goToIndex();
            });
            source.querySelector('[data-default-check-discard-keep]')?.addEventListener('click', () => discard?.close());
            source.querySelector('[data-default-check-discard-confirm-button]')?.addEventListener('click', () => { discard?.close(); goToIndex(); });
        });
    </script>
@endsection
