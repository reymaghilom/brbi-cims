<dialog id="dashboard-activity-dialog" class="fixed inset-0 m-auto h-[min(44rem,calc(100dvh-2rem))] max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-xl overflow-hidden rounded-panel border-0 bg-surface p-0 text-text-main shadow-float backdrop:bg-brand-sidebar/45" data-dashboard-activity-dialog aria-labelledby="dashboard-activity-dialog-title" aria-describedby="dashboard-activity-dialog-context">
    <div class="flex h-full min-h-0 flex-col">
        <header class="relative flex shrink-0 items-start justify-between gap-3 border-b border-ui-border bg-surface px-5 py-4 sm:px-6">
            <div class="min-w-0 pr-10">
                <h2 id="dashboard-activity-dialog-title" class="break-words text-lg font-bold text-brand-sidebar" data-dashboard-activity-title>CI Activity</h2>
                <p id="dashboard-activity-dialog-context" class="mt-1 break-words text-sm text-text-muted" data-dashboard-activity-context>Loading exact activity context...</p>
            </div>
            <button type="button" data-modal-close class="ui-icon-button absolute right-3 top-3" aria-label="Close CI Activity"><x-ui.icon name="close" size="size-5" /></button>
        </header>
        <div class="relative min-h-0 flex-1 overflow-hidden overscroll-contain bg-surface-subtle/40">
            <div class="absolute inset-0 z-10 grid place-items-center bg-surface-subtle" data-dashboard-activity-loading>
                <x-ui.loading-state label="Loading CI Activity form" />
            </div>
            <iframe class="block h-full w-full border-0 bg-surface-subtle/40" title="CI Activity update form" data-dashboard-activity-frame></iframe>
        </div>
    </div>
</dialog>
