@php($isEditModal = $modalMode === 'edit' && $managedUser)

<x-ui.modal
    id="user-form-dialog"
    :title="$isEditModal ? 'Edit User' : 'Create User'"
    :description="$isEditModal ? 'Update the selected staff account and approved system role.' : 'Create an authorized staff account with a temporary password.'"
    size="max-w-3xl"
    :body-scroll-only="true"
    class="group/user-modal"
    data-user-form-dialog
    data-scroll-body-only="true"
    data-open-on-error="{{ $openOnError ? 'true' : 'false' }}"
>
        <div class="mb-5 flex items-start gap-2.5 rounded-control border border-progress/25 bg-progress-soft px-3.5 py-3 text-sm leading-5 text-progress" role="status" aria-live="polite" data-user-modal-notice data-user-modal-notice-user-id="{{ $managedUser?->id }}" @if(! ($isEditModal && session('user_modal_notice'))) hidden @endif>
            <x-ui.icon name="info" size="mt-0.5 size-4" />
            <p data-user-modal-notice-message>{{ session('user_modal_notice') }}</p>
        </div>

    <form
        id="user-management-form"
        method="POST"
        action="{{ $isEditModal ? route('admin.users.update', $managedUser) : route('admin.users.store') }}"
        enctype="multipart/form-data"
        data-user-management-form
        data-user-store-url="{{ route('admin.users.store') }}"
    >
        @csrf
        <input type="hidden" name="_method" value="PUT" data-user-method @disabled(! $isEditModal)>
        <input type="hidden" name="user_modal" value="{{ $isEditModal ? 'edit' : 'create' }}" data-user-modal-mode>
        <input type="hidden" name="user_modal_id" value="{{ $managedUser?->id }}" data-user-modal-id>

        <x-ui.form-section title="Account information" description="Enter the staff member's identifying information and approved system role." class="border-0 p-0 shadow-none sm:p-0">
            @include('admin.users._form', ['managedUser' => $managedUser])
        </x-ui.form-section>

        <x-ui.form-section title="Temporary password" description="The user must replace this password after their first successful sign-in." class="mt-6" data-user-password-section :hidden="$isEditModal">
            <x-form.input name="password" label="Temporary password" type="password" :required="! $isEditModal" minlength="{{ \App\Support\Authentication\PasswordPolicy::MIN_LENGTH }}" autocomplete="new-password" help="Use at least {{ \App\Support\Authentication\PasswordPolicy::MIN_LENGTH }} characters." data-user-password :disabled="$isEditModal" />
            <x-form.input name="password_confirmation" label="Confirm temporary password" type="password" :required="! $isEditModal" minlength="{{ \App\Support\Authentication\PasswordPolicy::MIN_LENGTH }}" autocomplete="new-password" data-user-password :disabled="$isEditModal" />
        </x-ui.form-section>

        <section class="mt-6 rounded-card border border-ui-border bg-surface-muted p-5" data-user-account-actions @if(! $isEditModal || auth()->user()->is($managedUser)) hidden @endif>
            <h3 class="ui-section-title">Account actions</h3>
            <p class="mt-2 text-sm leading-6 text-text-muted">Security actions invalidate the affected user's existing sessions.</p>
            <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                <button type="button" data-modal-open="user-status-dialog" data-user-status-trigger data-user-name="{{ $managedUser?->full_name }}" data-user-status="{{ $managedUser?->status?->value }}" data-user-status-action="{{ $managedUser ? route('admin.users.status.update', $managedUser) : '' }}" class="{{ $managedUser?->status === App\Enums\UserStatus::Active ? 'ui-button-danger' : 'ui-button-primary' }}">{{ $managedUser?->status === App\Enums\UserStatus::Active ? 'Disable account' : 'Activate account' }}</button>
                <button type="button" data-modal-open="user-reset-password-dialog" data-user-password-reset-trigger data-user-name="{{ $managedUser?->full_name }}" data-user-password-reset-action="{{ $managedUser ? route('admin.users.password.reset', $managedUser) : '' }}" class="ui-button-secondary">Reset password</button>
            </div>
        </section>
    </form>

    <x-slot:footer>
        <button type="button" data-modal-close class="ui-button-secondary w-full sm:w-auto">
            <x-ui.icon name="close" />
            <span>Cancel</span>
        </button>
        <button type="submit" form="user-management-form" class="ui-button-primary w-full sm:w-auto">
            <x-ui.icon name="user-plus" data-user-create-icon class="group-has-[[data-user-method]:not(:disabled)]/user-modal:hidden" />
            <x-ui.icon name="check" data-user-save-icon class="hidden group-has-[[data-user-method]:not(:disabled)]/user-modal:block" />
            <span data-user-form-submit>{{ $isEditModal ? 'Save Changes' : 'Create User' }}</span>
        </button>
    </x-slot:footer>
</x-ui.modal>

<x-ui.confirmation-dialog id="user-status-dialog" title="Change this account's status?" action="#" method="PATCH" confirm-label="Confirm">
    <x-slot:formFields><input type="hidden" name="status" value="" data-user-status-value></x-slot:formFields>
    <p data-user-status-message>The selected account's sign-in access will be updated.</p>
</x-ui.confirmation-dialog>

<x-ui.confirmation-dialog id="user-reset-password-dialog" title="Reset this user's password?" action="#" confirm-label="Reset password">
    <p>A temporary password will be generated and shown once. <span data-user-reset-password-name>The user</span> will be signed out and required to create a new password.</p>
</x-ui.confirmation-dialog>
