@props(['title', 'number', 'deletedAt', 'assignedCi' => null, 'deletedBy' => null, 'restoreAction' => null, 'purgeAction' => null])

<article {{ $attributes->class('ui-card flex flex-col gap-5 p-5 lg:flex-row lg:items-center lg:justify-between') }}>
    <div class="flex min-w-0 items-start gap-4">
        <span class="grid size-11 shrink-0 place-items-center rounded-card bg-danger-soft text-danger"><x-ui.icon name="trash" /></span>
        <div class="min-w-0">
            <p class="truncate font-bold">{{ $title }}</p>
            <p class="mt-1 text-sm text-text-muted">{{ $number }} &middot; Deleted {{ $deletedAt }}</p>
            <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-xs">
                @if($assignedCi)<div><dt class="inline font-semibold text-text-muted">Assigned CI:</dt> <dd class="inline font-bold">{{ $assignedCi }}</dd></div>@endif
                <div><dt class="inline font-semibold text-text-muted">Deleted by:</dt> <dd class="inline font-bold">{{ $deletedBy ?: 'Not recorded' }}</dd></div>
            </dl>
        </div>
    </div>
    @if($restoreAction || $purgeAction)
        <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
            @if($restoreAction)<button type="button" id="restore-{{ md5($restoreAction) }}" data-modal-open="restore-dialog-{{ md5($restoreAction) }}" class="ui-button-secondary">Restore</button>@endif
            @if($purgeAction)<button type="button" id="purge-{{ md5($purgeAction) }}" data-modal-open="purge-dialog-{{ md5($purgeAction) }}" class="ui-button-danger">Delete permanently</button>@endif
        </div>
    @else
        <p class="rounded-full bg-surface-muted px-3 py-1.5 text-xs font-bold text-text-muted">Administrator action required</p>
    @endif

    @if($restoreAction)
        <x-ui.confirmation-dialog id="restore-dialog-{{ md5($restoreAction) }}" title="Restore client folder?" :action="$restoreAction" method="PATCH" confirm-label="Restore folder">
            <p class="text-sm leading-6 text-text-muted">Restore <strong class="text-text-main">{{ $title }}</strong> with its original ID, folder number, progress and related records.</p>
        </x-ui.confirmation-dialog>
    @endif
    @if($purgeAction)
        <x-ui.confirmation-dialog id="purge-dialog-{{ md5($purgeAction) }}" title="Permanently delete folder?" :action="$purgeAction" method="DELETE" confirm-label="Delete permanently" destructive>
            <p class="text-sm leading-6 text-text-muted">This permanently removes the folder and database-dependent records. Folders with external or file-backed references are blocked until their approved cleanup workflow exists.</p>
            <label for="purge-confirmation-{{ md5($purgeAction) }}" class="ui-label mt-4">Type <strong>{{ $number }}</strong> to confirm</label>
            <x-slot:formFields><input id="purge-confirmation-{{ md5($purgeAction) }}" name="confirmation" class="ui-control" required autocomplete="off" aria-label="Folder number confirmation"></x-slot:formFields>
        </x-ui.confirmation-dialog>
    @endif
</article>
