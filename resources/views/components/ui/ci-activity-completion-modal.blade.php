@props(['dashboard' => false])

@php
    $prefix = $dashboard ? 'dashboard-overdue-complete' : 'quick-complete';
@endphp

<dialog id="{{ $prefix }}-activity-modal" class="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-md overflow-y-auto rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45" @if($dashboard) data-dashboard-overdue-complete-modal data-dashboard-completion-modal @else data-quick-complete-modal @endif aria-labelledby="{{ $prefix }}-activity-title">
    <div class="border-b border-ui-border px-5 py-4">
        <h2 id="{{ $prefix }}-activity-title" class="break-words text-lg font-bold text-brand-sidebar" @if($dashboard) data-dashboard-overdue-complete-title data-dashboard-completion-title @else data-quick-complete-title @endif></h2>
        <p class="mt-0.5 flex items-center gap-1.5 text-sm text-text-muted"><x-ui.icon name="check-circle" size="size-4" class="shrink-0 text-success" data-completion-icon="indicator" />Complete this activity?</p>
    </div>
    <div class="space-y-3 px-5 py-5 text-sm leading-6 text-text-muted">
        <div @if($dashboard) data-dashboard-overdue-complete-schedule-block @else data-quick-complete-schedule-block @endif hidden>
            <p class="text-xs font-bold uppercase tracking-wide text-text-muted">Schedule</p>
            <p class="mt-0.5 font-semibold text-text-main" @if($dashboard) data-dashboard-overdue-complete-schedule @else data-quick-complete-schedule @endif></p>
        </div>
        <div @if($dashboard) data-dashboard-overdue-complete-remarks-block @else data-quick-complete-remarks-block @endif hidden>
            <p class="text-xs font-bold uppercase tracking-wide text-text-muted">Remarks</p>
            <p class="mt-0.5 break-words text-text-main" @if($dashboard) data-dashboard-overdue-complete-remarks @else data-quick-complete-remarks @endif></p>
        </div>
        <p class="text-xs text-text-muted">The schedule and time will be cleared. This completion will be recorded in Recent Activity under the user who confirms it.</p>
        <p class="rounded-control border border-danger/25 bg-danger-soft px-3 py-2 font-semibold text-danger" role="alert" tabindex="-1" @if($dashboard) data-dashboard-overdue-complete-error data-dashboard-completion-error @else data-quick-complete-error @endif hidden></p>
    </div>
    <div class="flex flex-col-reverse gap-2.5 border-t border-ui-border px-5 py-4 sm:flex-row sm:justify-end">
        <button type="button" class="ui-button-secondary w-full sm:w-auto" @if($dashboard) data-dashboard-overdue-complete-cancel data-dashboard-completion-cancel @else data-quick-complete-cancel @endif><x-ui.icon name="close" size="size-4" data-completion-icon="cancel" />Cancel</button>
        @unless($dashboard)
            <button type="button" class="ui-button-secondary w-full sm:w-auto" data-quick-complete-edit><x-ui.icon name="edit" size="size-4" data-completion-icon="edit" />Edit</button>
        @endunless
        <button type="button" class="ui-button-primary w-full sm:w-auto" @if($dashboard) data-dashboard-overdue-complete-confirm data-dashboard-completion-confirm @else data-quick-complete-confirm @endif><x-ui.icon name="check" size="size-4" data-completion-icon="confirm" /><span @if($dashboard) data-dashboard-overdue-complete-confirm-label data-dashboard-completion-confirm-label @else data-quick-complete-confirm-label @endif>Mark as Completed</span></button>
    </div>
</dialog>
