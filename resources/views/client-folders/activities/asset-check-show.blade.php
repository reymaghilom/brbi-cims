@extends('layouts.app')

@section('title', 'Asset Check')

@section('content')
    @php
        $personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null);
        $completedCount = $activity->assetTargets->where('status', App\Enums\ActivityStatus::Completed)->count();
        $targetCount = $activity->assetTargets->count();
        $context = $activePerson ? 'Co-Maker: '.$activePerson->full_name : 'Applicant: '.$clientFolder->display_name;
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
            <div class="flex flex-col gap-3 border-b border-ui-border pb-4 sm:flex-row sm:items-center sm:justify-between"><div><h2 id="asset-targets-title" class="ui-section-title">Assessor Offices</h2><p class="mt-1 text-sm text-text-muted">Each office keeps its own status, schedule, remarks, and updater.</p></div><div class="w-fit rounded-full bg-brand-soft px-3 py-1.5 text-sm font-bold text-brand-primary">{{ $completedCount }} of {{ $targetCount }} Completed</div></div>
            <div class="mt-4 grid gap-3 lg:grid-cols-2" data-asset-target-list>
                @forelse($activity->assetTargets as $target)
                    @php
                        $localSchedule = $target->scheduled_at?->timezone(config('cims.display_timezone'));
                        $targetCompleted = $target->status === App\Enums\ActivityStatus::Completed;
                        $targetLabel = $target->assessorLabel().' — '.$target->office_location;
                    @endphp
                    <article class="min-w-0 rounded-card border border-ui-border bg-surface p-4 shadow-sm" data-asset-target-card="{{ $target->id }}">
                        <div class="flex items-start gap-3">
                            <label class="mt-0.5 inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-control has-[:disabled]:cursor-default" title="{{ $targetCompleted ? $targetLabel.' is completed' : 'Mark '.$targetLabel.' as completed' }}">
                                <input type="checkbox" class="ci-completion-checkbox" aria-label="{{ $targetCompleted ? $targetLabel.' is completed' : 'Mark '.$targetLabel.' as completed' }}" @checked($targetCompleted) @disabled($targetCompleted) @if(! $targetCompleted) data-asset-target-complete data-asset-modal-open="complete-asset-target-{{ $target->id }}" @endif>
                            </label>
                            <div class="min-w-0 flex-1"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><h3 class="break-words text-base font-bold text-brand-sidebar">{{ $target->assessorLabel() }}</h3><p class="mt-0.5 break-words text-sm text-text-muted">{{ $target->office_location }}</p></div><span class="inline-flex shrink-0 items-center gap-1">@if($targetCompleted)<x-ui.icon name="check-circle" size="size-4 text-success" />@endif<x-ui.status-badge :status="$target->status" /></span></div></div>
                        </div>
                        @if($localSchedule)<p class="mt-3 flex flex-wrap items-center gap-1.5 text-sm font-semibold text-text-main"><x-ui.icon name="calendar" size="size-4 text-text-muted" />{{ $localSchedule->format('M j, Y') }} <span class="font-normal text-text-muted">· {{ $target->scheduled_has_time ? $localSchedule->format('g:i A') : 'No specific time' }}</span></p>@elseif(in_array($target->status, [App\Enums\ActivityStatus::Scheduled, App\Enums\ActivityStatus::FollowUp], true))<p class="mt-3 text-sm text-text-muted">No date set</p>@endif
                        @if($target->remarks)<p class="mt-3 whitespace-pre-line break-words text-sm leading-6 text-text-muted">{{ $target->remarks }}</p>@endif
                        <div class="mt-4 flex items-center justify-end border-t border-ui-border pt-3">
                            <x-ui.context-menu :label="'Actions for '.$targetLabel">
                                <x-slot:trigger><span class="ui-dots-trigger !size-8"><x-ui.icon name="more-vertical" size="size-4" /></span></x-slot:trigger>
                                <button type="button" role="menuitem" class="client-folder-menu-item" data-asset-modal-open="edit-asset-target-{{ $target->id }}"><x-ui.icon name="edit" size="size-4" class="text-text-muted" />Edit</button>
                                <div class="my-1 border-t border-ui-border"></div>
                                <button type="button" role="menuitem" class="client-folder-menu-item text-danger" data-asset-modal-open="delete-asset-target-{{ $target->id }}"><x-ui.icon name="trash" size="size-4" />Delete</button>
                            </x-ui.context-menu>
                        </div>
                    </article>
                @empty
                    <div class="rounded-control border border-dashed border-ui-border-strong bg-surface-subtle p-6 text-center lg:col-span-2"><p class="font-semibold text-text-main">No assessor targets yet.</p><p class="mt-1 text-sm text-text-muted">The Asset Check parent remains Pending until an assessor is added.</p></div>
                @endforelse
            </div>
        </section>

        <dialog id="add-asset-target" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-xl overflow-hidden rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45">
            <form method="POST" action="{{ route('client-folders.activities.asset-targets.store', [$clientFolder, $activity]) }}" class="flex max-h-[calc(100dvh-2rem)] flex-col" data-asset-target-form>@csrf<input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}"><div class="flex items-start justify-between gap-4 border-b border-ui-border px-5 py-4 sm:px-6"><div><h2 class="text-lg font-bold text-brand-sidebar">Add Assessor</h2><p class="mt-1 text-sm text-text-muted">Add another office to this Asset Check.</p></div><button type="button" class="ui-icon-button" data-asset-modal-close aria-label="Close"><x-ui.icon name="close" size="size-5" /></button></div><div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">@include('client-folders.activities.partials.asset-target-fields', ['prefix' => 'add', 'target' => null])</div><div class="flex flex-col-reverse gap-3 border-t border-ui-border px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" class="ui-button-secondary" data-asset-modal-close>Cancel</button><button type="submit" class="ui-button-primary">Add Assessor</button></div></form>
        </dialog>

        @foreach($activity->assetTargets as $target)
            <dialog id="complete-asset-target-{{ $target->id }}" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45"><form method="POST" action="{{ route('client-folders.activities.asset-targets.complete', [$clientFolder, $activity, $target]) }}" data-asset-target-form>@csrf @method('PATCH')<input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}"><div class="p-5 sm:p-6"><h2 class="text-lg font-bold text-brand-sidebar">Mark as completed?</h2><p class="mt-3 break-words text-sm text-text-muted">Mark <span class="font-semibold text-text-main">{{ $target->assessorLabel() }} — {{ $target->office_location }}</span> as completed?</p><div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><button type="button" class="ui-button-secondary" data-asset-modal-close>Cancel</button><button type="submit" class="ui-button-primary">Mark Completed</button></div></div></form></dialog>
            <dialog id="edit-asset-target-{{ $target->id }}" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-xl overflow-hidden rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45"><form method="POST" action="{{ route('client-folders.activities.asset-targets.update', [$clientFolder, $activity, $target]) }}" class="flex max-h-[calc(100dvh-2rem)] flex-col" data-asset-target-form>@csrf @method('PUT')<input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}"><div class="flex items-start justify-between border-b border-ui-border px-5 py-4 sm:px-6"><h2 class="text-lg font-bold text-brand-sidebar">Edit Assessor Target</h2><button type="button" class="ui-icon-button" data-asset-modal-close aria-label="Close"><x-ui.icon name="close" size="size-5" /></button></div><div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">@include('client-folders.activities.partials.asset-target-fields', ['prefix' => 'edit-'.$target->id, 'target' => $target])</div><div class="flex flex-col-reverse gap-3 border-t border-ui-border px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" class="ui-button-secondary" data-asset-modal-close>Cancel</button><button type="submit" class="ui-button-primary">Save</button></div></form></dialog>
            <dialog id="delete-asset-target-{{ $target->id }}" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45"><form method="POST" action="{{ route('client-folders.activities.asset-targets.destroy', [$clientFolder, $activity, $target]) }}" data-asset-target-form>@csrf @method('DELETE')<input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}"><div class="p-5 sm:p-6"><h2 class="text-lg font-bold text-brand-sidebar">Delete Assessor Target?</h2><p class="mt-3 break-words text-sm text-text-muted">This removes only <span class="font-semibold text-text-main">{{ $target->assessorLabel() }} — {{ $target->office_location }}</span>. The Asset Check activity will remain.</p><div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><button type="button" class="ui-button-secondary" data-asset-modal-close>Cancel</button><button type="submit" class="ui-button-danger">Delete Target</button></div></div></form></dialog>
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
            document.addEventListener('click', (event) => {
                const trigger = event.target.closest('[data-asset-modal-open]');
                const close = event.target.closest('[data-asset-modal-close]');
                if (close) { close.closest('dialog')?.close(); return; }
                if (! trigger) return;
                event.preventDefault();
                if (trigger.matches('[data-asset-target-complete]')) trigger.checked = false;
                const dialog = document.getElementById(trigger.dataset.assetModalOpen ?? '');
                if (dialog instanceof HTMLDialogElement) dialog.showModal();
            });
        });
    </script>
@endsection
