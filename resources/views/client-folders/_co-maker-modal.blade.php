<x-ui.modal
    id="co-maker-dialog"
    title="{{ old('co_maker_id') ? 'Edit Co-Maker' : 'Add Co-Maker' }}"
    description="Add or update a co-maker linked to this client folder. A client folder can have more than one."
    size="max-w-2xl"
    data-co-maker-modal
    {{-- Only this form's own errors may reopen this dialog. Keyed off $errors->any(), every
         other failed POST that redirects back to this page (a rejected signatory reassignment,
         for one) popped Add Co-Maker open on top of the folder the user was actually looking at.
         Scoped the same way the folder-rename dialog already scopes its own flag. --}}
    data-open-on-error="{{ $errors->hasAny(['co_maker_id', 'last_name', 'first_name', 'middle_name', 'suffix']) ? 'true' : 'false' }}"
>
    <form id="co-maker-form" method="POST" action="{{ route('client-folders.co-maker.store', $clientFolder) }}" data-co-maker-form novalidate>
        @csrf
        <input type="hidden" name="co_maker_id" value="{{ old('co_maker_id') }}" data-co-maker-id-field>
        {{-- Editing only: the revision this form was opened with, so a save that another user already
             superseded is refused instead of overwriting their changes. Empty when adding. --}}
        <input type="hidden" name="expected_revision" value="{{ old('expected_revision') }}" data-co-maker-revision-field>
        {{-- Set only by "Continue Anyway" on the duplicate-name advisory, then cleared again. --}}
        <input type="hidden" name="duplicate_confirmed" value="" data-co-maker-duplicate-confirmed>
        {{-- Why a save was refused as a whole (another user updated or deleted this Co-Maker). Shown
             here, inside the dialog, never only as a toast; field validation stays beside each field. --}}
        <p class="mb-4 flex items-start gap-2 rounded-control border border-danger/30 bg-danger-soft p-3 text-sm font-semibold text-danger" role="alert" aria-live="assertive" data-co-maker-form-error hidden></p>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="co-maker-last-name" class="ui-label">Last name <span class="text-danger" aria-hidden="true">*</span></label>
                <input id="co-maker-last-name" name="last_name" class="ui-control" required maxlength="255" autocomplete="family-name" value="{{ old('last_name') }}" aria-describedby="co-maker-last-name-error" autofocus>
                <p id="co-maker-last-name-error" class="mt-2 text-sm font-semibold text-danger" role="alert" data-co-maker-error-for="last_name" @if(! $errors->has('last_name')) hidden @endif>{{ $errors->first('last_name') }}</p>
            </div>
            <div>
                <label for="co-maker-first-name" class="ui-label">First name <span class="text-danger" aria-hidden="true">*</span></label>
                <input id="co-maker-first-name" name="first_name" class="ui-control" required maxlength="255" autocomplete="given-name" value="{{ old('first_name') }}" aria-describedby="co-maker-first-name-error">
                <p id="co-maker-first-name-error" class="mt-2 text-sm font-semibold text-danger" role="alert" data-co-maker-error-for="first_name" @if(! $errors->has('first_name')) hidden @endif>{{ $errors->first('first_name') }}</p>
            </div>
            <div>
                <label for="co-maker-middle-name" class="ui-label">Middle name <span class="font-normal text-text-muted">(optional)</span></label>
                <input id="co-maker-middle-name" name="middle_name" class="ui-control" maxlength="255" autocomplete="additional-name" value="{{ old('middle_name') }}" aria-describedby="co-maker-middle-name-error">
                <p id="co-maker-middle-name-error" class="mt-2 text-sm font-semibold text-danger" role="alert" data-co-maker-error-for="middle_name" @if(! $errors->has('middle_name')) hidden @endif>{{ $errors->first('middle_name') }}</p>
            </div>
            <div>
                <label for="co-maker-suffix" class="ui-label">Suffix <span class="font-normal text-text-muted">(optional)</span></label>
                <input id="co-maker-suffix" name="suffix" class="ui-control" maxlength="30" placeholder="JR., SR., III" value="{{ old('suffix') }}" aria-describedby="co-maker-suffix-error">
                <p id="co-maker-suffix-error" class="mt-2 text-sm font-semibold text-danger" role="alert" data-co-maker-error-for="suffix" @if(! $errors->has('suffix')) hidden @endif>{{ $errors->first('suffix') }}</p>
            </div>
        </div>
    </form>

    <x-slot:footer>
        <button type="button" data-modal-close class="ui-button-secondary"><x-ui.icon name="close" size="size-4" />Cancel</button>
        <button type="submit" form="co-maker-form" class="ui-button-primary" data-co-maker-submit><x-ui.icon name="check" size="size-4" /><span data-co-maker-submit-label>{{ old('co_maker_id') ? 'Update Co-Maker' : 'Save Co-Maker' }}</span></button>
    </x-slot:footer>
</x-ui.modal>

{{-- Duplicate-name advisory (same first and last name as another Co-Maker in this folder). Opened
     on top of the Add/Edit dialog, which stays open behind it. Cancel saves nothing; Continue
     Anyway resubmits the same form once with duplicate_confirmed=1, which waives only this
     advisory — never validation, revision/stale-save protection or ownership checks. --}}
<x-ui.modal id="co-maker-duplicate-dialog" title="Possible Duplicate Co-Maker" size="max-w-md" data-co-maker-duplicate-dialog>
    <div class="flex items-start gap-3 rounded-control border border-progress/30 bg-progress-soft p-3.5 text-progress">
        <x-ui.icon name="warning" size="size-5" class="mt-0.5 shrink-0" aria-hidden="true" />
        <p class="min-w-0 text-sm font-semibold leading-6" data-co-maker-duplicate-message>A Co-Maker with the same first and last name already exists in this Client Folder. Please verify the details before continuing.</p>
    </div>
    <x-slot:footer>
        <div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:justify-end" data-co-maker-duplicate-actions>
            <button type="button" data-modal-close class="ui-button-secondary w-full shrink-0 px-2 text-center leading-5 sm:w-auto sm:whitespace-nowrap sm:px-4"><x-ui.icon name="close" size="size-4" class="hidden sm:block" />Cancel</button>
            <button type="button" class="ui-button-primary w-full shrink-0 px-2 text-center leading-5 sm:w-auto sm:whitespace-nowrap sm:px-4" data-co-maker-duplicate-continue><x-ui.icon name="check" size="size-4" class="hidden sm:block" />Continue Anyway</button>
        </div>
    </x-slot:footer>
</x-ui.modal>
