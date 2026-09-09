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
