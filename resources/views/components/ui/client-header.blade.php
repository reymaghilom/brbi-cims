@props(['name', 'folderNumber', 'status' => 'on_progress', 'progress' => 0, 'assignedCi' => null, 'createdAt' => null, 'updatedAt' => null])

<section {{ $attributes->class('ui-panel p-3.5 sm:p-4') }}>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex min-w-0 items-center gap-3.5">
            <span class="grid size-11 shrink-0 place-items-center rounded-card bg-folder-soft text-progress sm:size-12"><x-ui.icon name="folder" size="size-6" /></span>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2"><p class="text-xs font-medium text-text-muted">{{ $folderNumber }}</p><x-ui.status-badge :status="$status" /></div>
                <h1 class="text-xl font-bold leading-tight tracking-tight text-brand-sidebar sm:text-2xl">{{ $name }}</h1>
            </div>
        </div>
        @isset($personActions)
            <div class="flex shrink-0 flex-col gap-1.5 lg:items-end">{{ $personActions }}</div>
        @endisset
    </div>
    @isset($actions)<div class="mt-5 flex flex-wrap gap-3">{{ $actions }}</div>@endisset
</section>
