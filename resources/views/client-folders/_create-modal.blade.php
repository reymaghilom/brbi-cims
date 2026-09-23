@can('create', App\Models\ClientFolder::class)
    <x-ui.modal
        id="create-client-folder-dialog"
        title="Create Client Folder"
        description="Enter the required client details. The folder number is generated automatically."
        size="max-w-2xl"
        data-create-folder-modal
    >
        <form id="create-client-folder-form" method="POST" action="{{ route('client-folders.store') }}" data-folder-create-form novalidate>
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="create-folder-last-name" class="ui-label">Last name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="create-folder-last-name" name="last_name" class="ui-control" required maxlength="100" autocomplete="family-name" aria-describedby="create-folder-last-name-error" autofocus>
                    <p id="create-folder-last-name-error" class="mt-2 text-sm font-semibold text-danger" role="alert" data-create-error-for="last_name" hidden></p>
                </div>
                <div>
                    <label for="create-folder-first-name" class="ui-label">First name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="create-folder-first-name" name="first_name" class="ui-control" required maxlength="100" autocomplete="given-name" aria-describedby="create-folder-first-name-error">
                    <p id="create-folder-first-name-error" class="mt-2 text-sm font-semibold text-danger" role="alert" data-create-error-for="first_name" hidden></p>
                </div>
                <div>
                    <label for="create-folder-middle-name" class="ui-label">Middle name <span class="font-normal text-text-muted">(optional)</span></label>
                    <input id="create-folder-middle-name" name="middle_name" class="ui-control" maxlength="100" autocomplete="additional-name" aria-describedby="create-folder-middle-name-error">
                    <p id="create-folder-middle-name-error" class="mt-2 text-sm font-semibold text-danger" role="alert" data-create-error-for="middle_name" hidden></p>
                </div>
                <div>
                    <label for="create-folder-suffix" class="ui-label">Suffix <span class="font-normal text-text-muted">(optional)</span></label>
                    <input id="create-folder-suffix" name="suffix" class="ui-control" maxlength="30" placeholder="JR., SR., III" aria-describedby="create-folder-suffix-error">
                    <p id="create-folder-suffix-error" class="mt-2 text-sm font-semibold text-danger" role="alert" data-create-error-for="suffix" hidden></p>
                </div>
            </div>
        </form>

        <x-slot:footer>
            <button type="button" data-modal-close class="ui-button-secondary">
                <x-ui.icon name="close" size="size-4" data-action-icon="close" />
                <span>Cancel</span>
            </button>
            <button type="submit" form="create-client-folder-form" class="ui-button-primary">
                <x-ui.icon name="plus" size="size-4" data-action-icon="plus" />
                <span>Create Client Folder</span>
            </button>
        </x-slot:footer>
    </x-ui.modal>
@endcan
