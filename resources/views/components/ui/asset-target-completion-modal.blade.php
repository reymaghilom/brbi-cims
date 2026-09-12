@props([
    'dashboard' => false,
    'target' => null,
    'clientFolder' => null,
    'activity' => null,
    'activePerson' => null,
])

@php
    $dialogId = $dashboard ? 'dashboard-overdue-asset-complete-modal' : 'complete-asset-target-'.$target->id;
    $targetLabel = $target ? $target->assessorLabel().' — '.$target->office_location : '';
@endphp

<dialog id="{{ $dialogId }}" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45" @if($dashboard) data-dashboard-overdue-asset-complete-modal data-dashboard-completion-modal @endif aria-labelledby="{{ $dialogId }}-title">
    <form @unless($dashboard) method="POST" action="{{ route('client-folders.activities.asset-targets.complete', [$clientFolder, $activity, $target]) }}" data-asset-target-form @endunless>
        @unless($dashboard)
            @csrf
            @method('PATCH')
            <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
        @endunless
        <div class="p-5 sm:p-6">
            <h2 id="{{ $dialogId }}-title" class="text-lg font-bold text-brand-sidebar">Mark as completed?</h2>
            <p class="mt-3 break-words text-sm text-text-muted">Mark <span class="font-semibold text-text-main" @if($dashboard) data-dashboard-completion-target @endif>{{ $targetLabel }}</span> as completed?</p>
            <p class="mt-3 rounded-control border border-danger/25 bg-danger-soft px-3 py-2 text-sm font-semibold text-danger" role="alert" tabindex="-1" @if($dashboard) data-dashboard-completion-error @endif hidden></p>
            <div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button type="button" class="ui-button-secondary" @if($dashboard) data-dashboard-completion-cancel @else data-asset-modal-close @endif><x-ui.icon name="close" size="size-4" data-completion-icon="cancel" />Cancel</button>
                <button type="{{ $dashboard ? 'button' : 'submit' }}" class="ui-button-primary" @if($dashboard) data-dashboard-completion-confirm @endif><x-ui.icon name="check" size="size-4" data-completion-icon="confirm" /><span @if($dashboard) data-dashboard-completion-confirm-label @endif>Mark Completed</span></button>
            </div>
        </div>
    </form>
</dialog>
