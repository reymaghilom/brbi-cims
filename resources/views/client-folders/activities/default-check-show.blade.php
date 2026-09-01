@extends('layouts.app')

@section('title', $activity->name)

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
        ['label' => $activity->name],
    ]" />

    <x-ui.page-header :title="$activity->name">
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
                    <h2 id="default-check-title" class="break-words text-lg font-bold text-brand-sidebar">{{ $activity->name }}</h2>
                    <p class="mt-1 break-words text-sm text-text-muted">{{ $contextLabel }}</p>
                </div>
                <x-ui.status-badge :status="$activity->status" />
            </div>

            <form method="POST" action="{{ route('client-folders.activities.update', [$clientFolder, $activity]) }}" class="p-5 sm:p-6" data-default-check-form data-current-status="{{ $activity->status->value }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
                <input type="hidden" name="expected_updated_at" value="{{ $activity->updated_at->toISOString() }}">
                <input type="hidden" name="intent" value="return">

                <div class="mb-4 rounded-control border border-danger/25 bg-danger-soft px-3.5 py-3 text-sm text-danger" data-default-check-errors hidden></div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="default-check-status-{{ $activity->id }}" class="ui-label">Status</label>
                        <select id="default-check-status-{{ $activity->id }}" name="status" class="ui-control" required data-default-check-status-control>
                            @foreach($statuses as $status)
                                <option value="{{ $status->value }}" @selected($activity->status === $status)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label for="default-check-date-{{ $activity->id }}" class="ui-label">Schedule / Follow-up Date <span class="font-normal text-text-muted">(optional)</span></label><input id="default-check-date-{{ $activity->id }}" name="scheduled_at" type="date" value="{{ $supportsSchedule ? $localSchedule?->format('Y-m-d') : '' }}" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-default-check-date @disabled(! $supportsSchedule)></div>
                        <div><label for="default-check-time-{{ $activity->id }}" class="ui-label">Time <span class="font-normal text-text-muted">(optional)</span></label><input id="default-check-time-{{ $activity->id }}" name="scheduled_time" type="time" value="{{ $supportsSchedule && $activity->scheduled_has_time ? $localSchedule?->format('H:i') : '' }}" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-default-check-time @disabled(! $supportsSchedule || ! $localSchedule)></div>
                        <p class="text-xs leading-5 text-text-muted sm:col-span-2">Date and time are optional. Select a date to enable a specific time.</p>
                    </div>
                    <div class="sm:col-span-2"><label for="default-check-remarks-{{ $activity->id }}" class="ui-label">Short Remarks <span class="font-normal text-text-muted">(optional)</span></label><textarea id="default-check-remarks-{{ $activity->id }}" name="remarks" rows="4" class="ui-control" data-default-check-remarks>{{ $activity->remarks }}</textarea></div>
                </div>

                <dl class="mt-5 grid gap-3 rounded-control bg-surface-subtle p-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-text-muted">Creator</dt><dd class="mt-1 break-words font-semibold text-text-main">{{ $activity->creator?->full_name ?? 'System-created' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-text-muted">Updated By</dt><dd class="mt-1 break-words font-semibold text-text-main">{{ $activity->updater?->full_name ?? 'Not updated yet' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-text-muted">Last Updated</dt><dd class="mt-1 text-text-main">{{ $activity->updated_at->timezone(config('cims.display_timezone'))->format('M j, Y · g:i A') }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-text-muted">Completed</dt><dd class="mt-1 text-text-main">{{ $activity->completed_at?->timezone(config('cims.display_timezone'))->format('M j, Y · g:i A') ?? 'Not completed' }}</dd></div>
                </dl>

                <div class="mt-5 flex flex-col-reverse gap-2.5 border-t border-ui-border pt-4 sm:flex-row sm:justify-end">
                    <button type="button" class="ui-button-secondary w-full sm:w-auto" data-default-check-close>Close</button>
                    <button type="submit" class="ui-button-primary w-full sm:w-auto" data-default-check-submit>Save Changes</button>
                </div>
            </form>
        </section>

        <dialog class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45" data-default-check-completion aria-labelledby="default-check-completion-title-{{ $activity->id }}">
            <div class="border-b border-ui-border px-5 py-4"><h2 id="default-check-completion-title-{{ $activity->id }}" class="text-lg font-bold text-brand-sidebar">Mark {{ $activity->name }} as completed?</h2></div>
            <div class="px-5 py-5 text-sm leading-6 text-text-muted">The schedule and time will be cleared. Completion will be recorded in Activity History under the actual user confirming this action.</div>
            <div class="flex flex-col-reverse gap-2.5 border-t border-ui-border px-5 py-4 sm:flex-row sm:justify-end"><button type="button" class="ui-button-secondary" data-default-check-completion-cancel>Cancel</button><button type="button" class="ui-button-primary" data-default-check-completion-confirm>Mark Completed</button></div>
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
            const completion = source.querySelector('[data-default-check-completion]');
            if (!(status instanceof HTMLSelectElement) || !(date instanceof HTMLInputElement) || !(time instanceof HTMLInputElement)) return;
            const sync = () => { const enabled = ['scheduled', 'follow_up'].includes(status.value); if (! enabled) { date.value = ''; time.value = ''; } date.disabled = ! enabled; if (! enabled || date.value === '') time.value = ''; time.disabled = ! enabled || date.value === ''; };
            status.addEventListener('change', sync); date.addEventListener('input', sync); sync();
            form.addEventListener('submit', (event) => { if (status.value === 'completed' && form.dataset.currentStatus !== 'completed' && form.dataset.completionConfirmed !== 'true' && completion instanceof HTMLDialogElement) { event.preventDefault(); completion.showModal(); } });
            source.querySelector('[data-default-check-completion-cancel]')?.addEventListener('click', () => completion?.close());
            source.querySelector('[data-default-check-completion-confirm]')?.addEventListener('click', () => { if (completion instanceof HTMLDialogElement) completion.close(); form.dataset.completionConfirmed = 'true'; form.requestSubmit(); });
            source.querySelector('[data-default-check-close]')?.addEventListener('click', () => window.location.assign(@js(route('client-folders.activities.index', [$clientFolder] + $personParams))));
        });
    </script>
@endsection
