@props([
    'dashboard' => false,
    'target' => null,
    'clientFolder' => null,
    'activity' => null,
    'activePerson' => null,
])

@php
    $dialogId = $dashboard ? 'dashboard-overdue-bank-complete-modal' : 'complete-bank-target-'.$target->id;
    $institution = $target?->targetLabel() ?? '';
    $targetType = $target?->inquiryTypeLabel() ?? '';
@endphp

<dialog id="{{ $dialogId }}" class="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-md overflow-y-auto rounded-panel border border-ui-border bg-surface p-0 text-text-main shadow-float backdrop:bg-brand-sidebar/60 backdrop:backdrop-blur-[1px]" @if($dashboard) data-dashboard-overdue-bank-complete-modal data-dashboard-completion-modal @endif aria-labelledby="{{ $dialogId }}-title" aria-describedby="{{ $dialogId }}-description">
    <form @unless($dashboard) method="POST" action="{{ route('client-folders.activities.bank-targets.complete', [$clientFolder, $activity, $target]) }}" @endunless>
        @unless($dashboard)
            @csrf
            @method('PATCH')
            <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
        @endunless

        <header class="flex items-center gap-3 border-b border-ui-border px-5 py-4">
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-success-soft text-success" aria-hidden="true"><x-ui.icon name="check-circle" size="size-5" data-completion-icon="indicator" /></span>
            <h2 id="{{ $dialogId }}-title" class="break-words text-lg font-bold text-brand-sidebar">Complete Bank / Coop Check?</h2>
        </header>

        <div class="space-y-4 px-5 py-5">
            <div class="rounded-control border border-ui-border bg-surface-subtle px-4 py-3.5" aria-label="Target to complete">
                <p class="break-words font-bold text-brand-sidebar" @if($dashboard) data-dashboard-completion-target @endif>{{ $institution }}</p>
                <p class="mt-1 inline-flex rounded-full bg-brand-soft px-2.5 py-1 text-xs font-bold text-brand-primary" @if($dashboard) data-dashboard-completion-target-type @endif>{{ $targetType }}</p>
            </div>
            <p id="{{ $dialogId }}-description" class="text-sm leading-6 text-text-muted">This will mark this target as completed and record the action in Recent Activity.</p>
            <p class="rounded-control border border-danger/25 bg-danger-soft px-3 py-2 text-sm font-semibold text-danger" role="alert" tabindex="-1" @if($dashboard) data-dashboard-completion-error @endif hidden></p>
        </div>

        <footer class="flex flex-col-reverse gap-2.5 border-t border-ui-border bg-surface-muted px-5 py-4 sm:flex-row sm:justify-end">
            <button type="button" class="ui-button-secondary w-full sm:w-auto" @if($dashboard) data-dashboard-completion-cancel @else data-modal-close @endif><x-ui.icon name="close" size="size-4" data-completion-icon="cancel" />Cancel</button>
            <button type="{{ $dashboard ? 'button' : 'submit' }}" class="ui-button-primary w-full sm:w-auto" @if($dashboard) data-dashboard-completion-confirm @endif><x-ui.icon name="check" size="size-4" data-completion-icon="confirm" /><span @if($dashboard) data-dashboard-completion-confirm-label @endif>Mark as Completed</span></button>
        </footer>
    </form>
</dialog>
