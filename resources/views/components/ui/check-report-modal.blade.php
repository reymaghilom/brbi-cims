@props(['id' => 'check-report-dialog'])

<dialog id="{{ $id }}" class="check-report-dialog fixed inset-0 m-auto h-[94dvh] max-h-[94dvh] w-[95vw] max-w-none overflow-hidden rounded-card border border-ui-border bg-surface p-0 text-text-main shadow-float backdrop:bg-brand-sidebar/65 backdrop:backdrop-blur-[1px]" data-check-report-dialog aria-labelledby="{{ $id }}-title">
    <div class="flex h-full min-h-0 flex-col">
        <header class="flex min-h-12 shrink-0 items-center justify-between gap-3 border-b border-ui-border bg-surface px-3 sm:px-4">
            <h2 id="{{ $id }}-title" class="text-sm font-semibold text-brand-sidebar">Residence &amp; Business Checks</h2>
            <button type="button" data-modal-close class="ui-icon-button" aria-label="Close"><x-ui.icon name="close" size="size-5" /></button>
        </header>
        <div class="relative min-h-0 flex-1 bg-app-bg">
            <div class="absolute inset-0 z-10 grid place-items-center bg-app-bg" data-check-report-loading>
                <x-ui.loading-state label="Loading form" />
            </div>
            <iframe class="h-full w-full border-0 bg-app-bg" title="Residence/Business Check encoding form" data-check-report-frame></iframe>
        </div>
    </div>
</dialog>
