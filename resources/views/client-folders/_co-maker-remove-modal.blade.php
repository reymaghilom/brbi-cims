<x-ui.modal
    id="co-maker-remove-dialog"
    title="Remove Co-Maker"
    size="max-w-sm"
    data-co-maker-remove-modal
>
    <div class="flex flex-col items-center gap-3 py-1 text-center">
        <span class="grid size-12 shrink-0 place-items-center rounded-full bg-progress-soft text-progress"><x-ui.icon name="warning" size="size-6" /></span>
        <div>
            <h3 class="text-base font-bold text-text-main">Remove <span class="uppercase" data-co-maker-remove-name></span> as Co-Maker?</h3>
            <p class="mt-1 text-sm text-text-muted">This action cannot be undone.</p>
        </div>
    </div>
    <form id="co-maker-remove-form" method="POST" data-co-maker-remove-form novalidate>
        @csrf
        @method('DELETE')
    </form>

    <x-slot:footer>
        <button type="button" data-modal-close class="ui-button-secondary">Cancel</button>
        <button type="submit" form="co-maker-remove-form" class="ui-button-danger" data-co-maker-remove-submit>Remove Co-Maker</button>
    </x-slot:footer>
</x-ui.modal>
