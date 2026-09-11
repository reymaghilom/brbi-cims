@extends('layouts.app')

@section('title', 'Asset Check')

@section('content')
    @php
        $personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null);
        $completedCount = $activity->assetTargets->where('status', App\Enums\ActivityStatus::Completed)->count();
        $targetCount = $activity->assetTargets->count();
        $context = $activePerson ? 'Co-Maker: '.$activePerson->full_name : 'Applicant: '.$clientFolder->display_name;
        $assetRemarks = $activity->assetTargets->pluck('remarks')->filter(fn ($remarks) => filled($remarks))->values();
    @endphp

    <x-ui.breadcrumb :items="[
        ['label' => 'Client Folders', 'url' => route('client-folders.index')],
        ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', [$clientFolder] + $personParams)],
        ['label' => 'CI Activities', 'url' => route('client-folders.activities.index', [$clientFolder] + $personParams)],
        ['label' => 'Asset Check'],
    ]" />

    <x-ui.page-header title="Asset Check" :description="$context">
        <x-slot:actions><button type="button" class="ui-button-primary" data-modal-open="add-asset-target"><x-ui.icon name="plus" size="size-4" />Add Assessor</button><a href="{{ route('client-folders.activities.index', [$clientFolder] + $personParams) }}" class="ui-button-secondary">All Activities</a></x-slot:actions>
    </x-ui.page-header>

    <div
        data-asset-check-modal-source
        data-asset-activity-id="{{ $activity->id }}"
        data-asset-context="{{ $context }}"
        data-asset-target-count="{{ $targetCount }}"
        data-asset-completed-count="{{ $completedCount }}"
        data-asset-remarks-preview="{{ $assetRemarks->first() }}"
        data-asset-remarks-count="{{ $assetRemarks->count() }}"
        data-asset-status="{{ $activity->status->value }}"
        data-asset-status-label="{{ $activity->status->label() }}"
        data-asset-updated-date="{{ $activity->updated_at->timezone(config('cims.display_timezone'))->format('M j, Y') }}"
        data-asset-updated-detail="{{ $activity->updated_at->timezone(config('cims.display_timezone'))->format('g:i A') }}{{ $activity->updater ? ' · '.$activity->updater->full_name : '' }}"
        data-asset-updated-timestamp="{{ $activity->updated_at->timestamp }}"
    >
    <template data-asset-schedule-cell>@include('client-folders.activities.partials.schedule-cell', ['activity' => $activity, 'isBankCoopCheck' => false, 'isAssetCheck' => true, 'scheduleSummary' => $scheduleSummary])</template>
    @if(! empty($newHistoryEntries ?? []))
        <template data-ci-new-history>{!! implode('', $newHistoryEntries) !!}</template>
    @endif
        <section class="ui-panel p-4 sm:p-5 lg:p-6" aria-labelledby="asset-targets-title">
            <div class="flex flex-col gap-3 border-b border-ui-border pb-4 sm:flex-row sm:items-center sm:justify-between"><div><h2 id="asset-targets-title" class="ui-section-title">Assessor Offices</h2><p class="mt-1 text-sm text-text-muted">Each office has its own status, schedule, remarks, and updater.</p></div><div class="w-fit rounded-full bg-brand-soft px-3 py-1.5 text-sm font-bold text-brand-primary">{{ $completedCount }} of {{ $targetCount }} Completed</div></div>
            @if($targetCount > 0)
                @php $incompleteTargetCount = $targetCount - $completedCount; @endphp
                <div class="mt-3 flex flex-col gap-2.5 border-b border-ui-border pb-3 sm:flex-row sm:items-center sm:justify-between" data-asset-bulk-panel>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-text-main">
                        <input type="checkbox" class="size-5 shrink-0 rounded border-ui-border-strong text-success focus:ring-success focus:ring-offset-2 disabled:cursor-default disabled:opacity-100" data-asset-bulk-select-all aria-label="Select all pending assessor targets" @disabled($incompleteTargetCount === 0)>
                        Select All
                    </label>
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-semibold text-text-muted" data-asset-bulk-counter>0 selected</span>
                        <button type="button" class="ui-button-primary-compact" data-asset-bulk-open-confirm disabled><x-ui.icon name="check" size="size-3.5" />Mark Selected as Completed</button>
                    </div>
                </div>
                <form method="POST" action="{{ route('client-folders.activities.asset-targets.complete-many', [$clientFolder, $activity]) }}" data-asset-target-form data-asset-bulk-form hidden>
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
                </form>

                <dialog id="asset-bulk-complete-confirm" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md overflow-hidden rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45" data-asset-bulk-confirm-modal>
                    <div class="border-b border-ui-border px-5 py-4"><h2 class="break-words text-lg font-bold text-brand-sidebar" data-asset-bulk-confirm-title>Mark selected assessor targets as Completed?</h2></div>
                    <div class="px-5 py-5 text-sm leading-6 text-text-muted"><p data-asset-bulk-confirm-body>This will mark all selected assessor targets as completed.</p></div>
                    <div class="flex flex-col-reverse gap-2.5 border-t border-ui-border px-5 py-4 sm:flex-row sm:justify-end"><button type="button" class="ui-button-secondary w-full sm:w-auto" data-asset-bulk-confirm-cancel><x-ui.icon name="close" size="size-4" />Cancel</button><button type="button" class="ui-button-primary w-full sm:w-auto" data-asset-bulk-confirm-submit><x-ui.icon name="check" size="size-4" />Mark as Completed</button></div>
                </dialog>
            @endif
            <ul class="mt-3 divide-y divide-ui-border overflow-hidden rounded-control border border-ui-border" data-asset-target-list>
                @forelse($activity->assetTargets as $target)
                    @php
                        $localSchedule = $target->scheduled_at?->timezone(config('cims.display_timezone'));
                        $targetCompleted = $target->status === App\Enums\ActivityStatus::Completed;
                        $targetLabel = $target->assessorLabel().' — '.$target->office_location;
                    @endphp
                    <li class="flex items-start gap-3 bg-surface px-3 py-2.5 sm:items-center" data-asset-target-card="{{ $target->id }}">
                        <input type="checkbox" class="mt-0.5 size-5 shrink-0 rounded border-ui-border-strong text-success focus:ring-success focus:ring-offset-2 disabled:cursor-default disabled:opacity-100 sm:mt-0" data-asset-bulk-target="{{ $target->id }}" aria-label="{{ $targetCompleted ? $targetLabel.' is completed' : 'Select '.$targetLabel.' for bulk completion' }}" @checked($targetCompleted) @disabled($targetCompleted)>
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-sm font-bold text-brand-sidebar">{{ $target->assessorLabel() }} <span class="font-normal text-text-muted">&mdash; {{ $target->office_location }}</span></p>
                            @if($localSchedule)<p class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-text-muted"><x-ui.icon name="calendar" size="size-3.5" />{{ $localSchedule->format('M j, Y') }} <span>&middot; {{ $target->scheduled_has_time ? $localSchedule->format('g:i A') : 'No specific time' }}</span></p>@elseif(in_array($target->status, [App\Enums\ActivityStatus::Scheduled, App\Enums\ActivityStatus::FollowUp], true))<p class="mt-1 text-xs text-text-muted">No date set</p>@endif
                            @if(filled($target->remarks))<p class="mt-1 truncate text-xs text-text-muted" title="{{ $target->remarks }}"><span class="font-semibold text-text-main">Remarks:</span> {{ $target->remarks }}</p>@endif
                        </div>
                        <x-ui.status-badge :status="$target->status" class="shrink-0" />
                        <div class="shrink-0">
                            <x-ui.context-menu :label="'Actions for '.$targetLabel">
                                <x-slot:trigger><span class="ui-dots-trigger !size-8"><x-ui.icon name="more-vertical" size="size-4" /></span></x-slot:trigger>
                                <button type="button" role="menuitem" class="client-folder-menu-item" data-asset-modal-open="edit-asset-target-{{ $target->id }}"><x-ui.icon name="edit" size="size-4" class="text-text-muted" />Edit Asset Check</button>
                                <button type="button" role="menuitem" class="client-folder-menu-item" data-asset-modal-open="complete-asset-target-{{ $target->id }}" @disabled($targetCompleted)><x-ui.icon name="check-circle" size="size-4" class="text-text-muted" />Mark Completed</button>
                                <div class="my-1 border-t border-ui-border"></div>
                                <button type="button" role="menuitem" class="client-folder-menu-item text-danger" data-asset-modal-open="delete-asset-target-{{ $target->id }}"><x-ui.icon name="trash" size="size-4" />Delete</button>
                            </x-ui.context-menu>
                        </div>
                    </li>
                @empty
                    <li class="bg-surface-subtle p-6 text-center"><p class="font-semibold text-text-main">No assessor targets yet.</p><p class="mt-1 text-sm text-text-muted">The Asset Check parent remains Pending until an assessor is added.</p></li>
                @endforelse
            </ul>
        </section>

        <dialog id="add-asset-target" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-xl overflow-hidden rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45">
            <form method="POST" action="{{ route('client-folders.activities.asset-targets.store', [$clientFolder, $activity]) }}" class="flex max-h-[calc(100dvh-2rem)] flex-col" data-asset-target-form>@csrf<input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}"><div class="flex items-start justify-between gap-4 border-b border-ui-border px-5 py-4 sm:px-6"><div><h2 class="text-lg font-bold text-brand-sidebar">Add Assessor</h2><p class="mt-1 text-sm text-text-muted">Add another office to this Asset Check.</p></div><button type="button" class="ui-icon-button" data-asset-modal-close aria-label="Close"><x-ui.icon name="close" size="size-5" /></button></div><div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">@include('client-folders.activities.partials.asset-target-fields', ['prefix' => 'add', 'target' => null])</div><div class="flex flex-col-reverse gap-3 border-t border-ui-border px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" class="ui-button-secondary" data-asset-modal-close><x-ui.icon name="close" size="size-4" />Cancel</button><button type="submit" class="ui-button-primary"><x-ui.icon name="check" size="size-4" />Add Assessor</button></div></form>
        </dialog>

        @foreach($activity->assetTargets as $target)
            <dialog id="complete-asset-target-{{ $target->id }}" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45"><form method="POST" action="{{ route('client-folders.activities.asset-targets.complete', [$clientFolder, $activity, $target]) }}" data-asset-target-form>@csrf @method('PATCH')<input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}"><div class="p-5 sm:p-6"><h2 class="text-lg font-bold text-brand-sidebar">Mark as completed?</h2><p class="mt-3 break-words text-sm text-text-muted">Mark <span class="font-semibold text-text-main">{{ $target->assessorLabel() }} — {{ $target->office_location }}</span> as completed?</p><div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><button type="button" class="ui-button-secondary" data-asset-modal-close><x-ui.icon name="close" size="size-4" />Cancel</button><button type="submit" class="ui-button-primary"><x-ui.icon name="check" size="size-4" />Mark Completed</button></div></div></form></dialog>
            <dialog id="edit-asset-target-{{ $target->id }}" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-xl overflow-hidden rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45"><form method="POST" action="{{ route('client-folders.activities.asset-targets.update', [$clientFolder, $activity, $target]) }}" class="flex max-h-[calc(100dvh-2rem)] flex-col" data-asset-target-form data-no-change-guard>@csrf @method('PUT')<input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}"><div class="flex items-start justify-between border-b border-ui-border px-5 py-4 sm:px-6"><h2 class="text-lg font-bold text-brand-sidebar">Edit Asset Check</h2><button type="button" class="ui-icon-button" data-asset-modal-close aria-label="Close"><x-ui.icon name="close" size="size-5" /></button></div><div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6"><p class="mb-4 flex items-start gap-1.5 rounded-control border border-progress/30 bg-progress-soft px-3 py-2 text-sm font-semibold text-progress" data-no-change-message role="status" aria-live="polite" hidden><x-ui.icon name="info" size="size-4" class="mt-0.5 shrink-0" aria-hidden="true" />No changes detected. Nothing needs to be updated.</p>@include('client-folders.activities.partials.asset-target-fields', ['prefix' => 'edit-'.$target->id, 'target' => $target])</div><div class="flex flex-col-reverse gap-3 border-t border-ui-border px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" class="ui-button-secondary" data-asset-modal-close><x-ui.icon name="close" size="size-4" />Cancel</button><button type="submit" class="ui-button-primary"><x-ui.icon name="check" size="size-4" />Save Changes</button></div></form></dialog>
            <dialog id="delete-asset-target-{{ $target->id }}" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45"><form method="POST" action="{{ route('client-folders.activities.asset-targets.destroy', [$clientFolder, $activity, $target]) }}" data-asset-target-form>@csrf @method('DELETE')<input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}"><div class="p-5 sm:p-6"><h2 class="text-lg font-bold text-brand-sidebar">Delete Assessor Target?</h2><p class="mt-3 break-words text-sm text-text-muted">This removes only <span class="font-semibold text-text-main">{{ $target->assessorLabel() }} — {{ $target->office_location }}</span>. The Asset Check activity will remain.</p><div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><button type="button" class="ui-button-secondary" data-asset-modal-close><x-ui.icon name="close" size="size-4" />Cancel</button><button type="submit" class="ui-button-danger"><x-ui.icon name="trash" size="size-4" />Delete Target</button></div></div></form></dialog>
        @endforeach
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const bind = (form) => {
                const status = form.querySelector('[data-asset-detail-status]'); const date = form.querySelector('[data-asset-detail-date]'); const time = form.querySelector('[data-asset-detail-time]');
                if (!(status instanceof HTMLSelectElement) || !(date instanceof HTMLInputElement) || !(time instanceof HTMLInputElement)) return;
                const sync = () => { const enabled = ['scheduled', 'follow_up'].includes(status.value); if (! enabled) { date.value = ''; time.value = ''; } date.disabled = ! enabled; if (! enabled || date.value === '') time.value = ''; time.disabled = ! enabled || date.value === ''; };
                status.addEventListener('change', sync); date.addEventListener('input', sync); sync();
            };
            document.querySelectorAll('[data-asset-target-form]').forEach((form) => bind(form));

            const bulkPanel = document.querySelector('[data-asset-bulk-panel]');
            if (bulkPanel) {
                const targets = () => [...document.querySelectorAll('[data-asset-bulk-target]')]
                    .filter((target) => target instanceof HTMLInputElement && ! target.disabled);

                const syncBulkPanel = () => {
                    const selectAll = bulkPanel.querySelector('[data-asset-bulk-select-all]');
                    const counter = bulkPanel.querySelector('[data-asset-bulk-counter]');
                    const openConfirm = bulkPanel.querySelector('[data-asset-bulk-open-confirm]');
                    if (!(selectAll instanceof HTMLInputElement)) return;

                    const checkboxTargets = [...document.querySelectorAll('[data-asset-bulk-target]')]
                        .filter((target) => target instanceof HTMLInputElement);
                    const eligible = targets();
                    const selected = eligible.filter((target) => target.checked);
                    const checked = checkboxTargets.filter((target) => target.checked);

                    selectAll.checked = checkboxTargets.length > 0 && checked.length === checkboxTargets.length;
                    selectAll.indeterminate = checked.length > 0 && checked.length < checkboxTargets.length;
                    if (counter instanceof HTMLElement) counter.textContent = `${selected.length} selected`;
                    if (openConfirm instanceof HTMLButtonElement) openConfirm.disabled = selected.length === 0;
                };

                bulkPanel.querySelector('[data-asset-bulk-select-all]')?.addEventListener('change', (event) => {
                    const selectAll = event.target;
                    if (!(selectAll instanceof HTMLInputElement)) return;
                    targets().forEach((target) => {
                        target.checked = selectAll.checked;
                    });
                    syncBulkPanel();
                });

                targets().forEach((target) => target.addEventListener('change', syncBulkPanel));

                const confirmModal = document.getElementById('asset-bulk-complete-confirm');
                bulkPanel.querySelector('[data-asset-bulk-open-confirm]')?.addEventListener('click', (event) => {
                    const button = event.currentTarget;
                    if (!(button instanceof HTMLButtonElement) || button.disabled) return;
                    const selected = targets().filter((target) => target.checked);
                    const title = confirmModal?.querySelector('[data-asset-bulk-confirm-title]');
                    const body = confirmModal?.querySelector('[data-asset-bulk-confirm-body]');
                    if (title instanceof HTMLElement) title.textContent = selected.length === 1
                        ? 'Mark this assessor target as Completed?'
                        : `Mark ${selected.length} assessor targets as Completed?`;
                    if (body instanceof HTMLElement) body.textContent = selected.length === 1
                        ? 'This will mark the selected assessor target as completed.'
                        : 'This will mark all selected assessor targets as completed.';
                    if (confirmModal instanceof HTMLDialogElement) confirmModal.showModal();
                });

                confirmModal?.querySelector('[data-asset-bulk-confirm-cancel]')?.addEventListener('click', () => {
                    if (confirmModal instanceof HTMLDialogElement) confirmModal.close();
                });

                confirmModal?.querySelector('[data-asset-bulk-confirm-submit]')?.addEventListener('click', () => {
                    const selected = targets().filter((target) => target.checked);
                    if (selected.length === 0) return;
                    const form = document.querySelector('[data-asset-bulk-form]');
                    if (!(form instanceof HTMLFormElement)) return;
                    form.querySelectorAll('input[name="asset_target_ids[]"]').forEach((input) => input.remove());
                    selected.forEach((target) => {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'asset_target_ids[]';
                        hidden.value = target.dataset.assetBulkTarget ?? '';
                        form.append(hidden);
                    });
                    if (confirmModal instanceof HTMLDialogElement) confirmModal.close();
                    form.requestSubmit();
                });

                syncBulkPanel();
            }
            document.addEventListener('click', (event) => {
                const trigger = event.target.closest('[data-asset-modal-open]');
                const close = event.target.closest('[data-asset-modal-close]');
                if (close) { close.closest('dialog')?.close(); return; }
                if (! trigger) return;
                event.preventDefault();
                const dialog = document.getElementById(trigger.dataset.assetModalOpen ?? '');
                if (dialog instanceof HTMLDialogElement) dialog.showModal();
            });
        });
    </script>
@endsection
