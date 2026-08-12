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
                    <label for="create-folder-middle-name" class="ui-label">Middle name <span aria-hidden="true" class="text-danger">*</span></label>
                    <input id="create-folder-middle-name" name="middle_name" class="ui-control" maxlength="100" autocomplete="additional-name" required aria-describedby="create-folder-middle-name-error">
                    <p id="create-folder-middle-name-error" class="mt-2 text-sm font-semibold text-danger" role="alert" data-create-error-for="middle_name" hidden></p>
                </div>
                <div>
                    <label for="create-folder-suffix" class="ui-label">Suffix <span class="font-normal text-text-muted">(optional)</span></label>
                    <input id="create-folder-suffix" name="suffix" class="ui-control" maxlength="30" placeholder="JR., SR., III" aria-describedby="create-folder-suffix-error">
                    <p id="create-folder-suffix-error" class="mt-2 text-sm font-semibold text-danger" role="alert" data-create-error-for="suffix" hidden></p>
                </div>

                @if(auth()->user()->role === App\Enums\UserRole::Administrator)
                    <div class="sm:col-span-2">
                        <label for="create-folder-assigned-ci" class="ui-label">Assigned Credit Investigator <span class="text-danger" aria-hidden="true">*</span></label>
                        <select id="create-folder-assigned-ci" name="assigned_ci_id" class="ui-control" required aria-describedby="create-folder-assigned-ci-help create-folder-assigned-ci-error">
                            <option value="">Select an active Credit Investigator</option>
                            @foreach($creditInvestigators as $investigator)
                                <option value="{{ $investigator->id }}">{{ $investigator->full_name }}{{ $investigator->employee_id ? ' — '.$investigator->employee_id : '' }}</option>
                            @endforeach
                        </select>
                        <p id="create-folder-assigned-ci-help" class="ui-help">Only active Credit Investigator accounts are available.</p>
                        <p id="create-folder-assigned-ci-error" class="mt-2 text-sm font-semibold text-danger" role="alert" data-create-error-for="assigned_ci_id" hidden></p>
                    </div>
                @else
                    <div class="rounded-card border border-brand-primary/20 bg-brand-soft p-4 sm:col-span-2">
                        <p class="text-sm font-semibold text-brand-primary">This folder will be assigned to your account.</p>
                        <p class="mt-1 text-sm text-text-muted">{{ auth()->user()->full_name }}</p>
                    </div>
                @endif
            </div>
        </form>

        <x-slot:footer>
            <button type="button" data-modal-close class="ui-button-secondary">Cancel</button>
            <button type="submit" form="create-client-folder-form" class="ui-button-primary">Create Client Folder</button>
        </x-slot:footer>
    </x-ui.modal>
@endcan
