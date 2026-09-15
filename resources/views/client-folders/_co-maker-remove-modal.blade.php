{{-- One shared dialog; the Remove trigger stamps the exact Co-Maker (id, name, saved-records flag) and
     app.js shows the matching state. Credit Investigators may only delete a Co-Maker that is still
     empty; Senior CIs and Administrators may also delete one with saved investigation records. The
     server (RemoveCoMaker) re-checks both rules and other users' unsaved work. --}}
<x-ui.modal
    id="co-maker-remove-dialog"
    title="Delete Co-Maker?"
    size="max-w-md"
    data-co-maker-remove-modal
    data-co-maker-delete-saved-allowed="{{ App\Actions\ClientFolders\RemoveCoMaker::mayDeleteSavedRecords(auth()->user()) ? '1' : '0' }}"
>
    <div data-co-maker-delete-confirm>
        <p class="text-sm font-semibold text-text-main">Co-Maker: <span class="uppercase" data-co-maker-remove-name></span></p>
        <p class="mt-2 text-sm leading-6 text-text-muted" data-co-maker-delete-empty-warning>Are you sure you want to delete this Co-Maker? This action cannot be undone.</p>
        <div class="mt-2 flex items-start gap-3 rounded-control border border-danger/30 bg-danger-soft p-3.5 text-danger" data-co-maker-delete-data-warning hidden>
            <x-ui.icon name="warning" size="size-5" class="mt-0.5 shrink-0" aria-hidden="true" />
            <p class="min-w-0 text-sm font-semibold leading-6">This will permanently delete this Co-Maker and all records linked to them. This action cannot be undone.</p>
        </div>
        {{-- A refusal from the server (saved records, another user's unsaved work, a failure) is shown here. --}}
        <p class="mt-3 flex items-start gap-2 rounded-control border border-danger/30 bg-danger-soft p-3 text-sm font-semibold text-danger" role="alert" data-co-maker-delete-error hidden></p>
    </div>
    <div class="flex items-start gap-3 rounded-control border border-danger/30 bg-danger-soft p-3.5 text-danger" data-co-maker-delete-unavailable hidden>
        <x-ui.icon name="warning" size="size-5" class="mt-0.5 shrink-0" aria-hidden="true" />
        <p class="min-w-0 text-sm font-semibold leading-6">This Co-Maker already has saved records. Only a Senior CI or Administrator can delete it.</p>
    </div>
    <form id="co-maker-remove-form" method="POST" data-co-maker-remove-form novalidate>
        @csrf
        @method('DELETE')
    </form>

    <x-slot:footer>
        {{-- Phones: two equal columns (icons hidden, text may wrap; the grid keeps both the same height). Inline from sm up. --}}
        <div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:justify-end" data-co-maker-delete-actions>
            <button type="button" data-modal-close class="ui-button-secondary w-full shrink-0 px-2 text-center leading-5 sm:w-auto sm:whitespace-nowrap sm:px-4"><x-ui.icon name="close" size="size-4" class="hidden sm:block" />Cancel</button>
            <button type="submit" form="co-maker-remove-form" class="ui-button-danger w-full shrink-0 px-2 text-center leading-5 sm:w-auto sm:whitespace-nowrap sm:px-4" data-co-maker-remove-submit><x-ui.icon name="trash" size="size-4" class="hidden sm:block" />Delete</button>
        </div>
        <button type="button" data-modal-close class="ui-button-secondary w-full shrink-0 whitespace-nowrap sm:w-auto" data-co-maker-delete-close hidden><x-ui.icon name="close" size="size-4" />Close</button>
    </x-slot:footer>
</x-ui.modal>
