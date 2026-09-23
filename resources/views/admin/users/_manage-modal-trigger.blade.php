<button
    type="button"
    class="ui-button-secondary"
    data-modal-open="user-form-dialog"
    data-user-form-trigger
    data-user-form-mode="edit"
    data-user-form-action="{{ route('admin.users.update', $managedUser) }}"
    data-user-id="{{ $managedUser->id }}"
    data-user-full-name="{{ $managedUser->full_name }}"
    data-user-username="{{ $managedUser->username }}"
    data-user-email="{{ $managedUser->email }}"
    data-user-role="{{ $managedUser->role->value }}"
    data-user-status="{{ $managedUser->status->value }}"
    data-user-photo-url="{{ $managedUser->profilePhotoUrl() }}"
    data-user-status-action="{{ route('admin.users.status.update', $managedUser) }}"
    data-user-password-reset-action="{{ route('admin.users.password.reset', $managedUser) }}"
    data-user-is-self="{{ auth()->user()->is($managedUser) ? 'true' : 'false' }}"
>
    <x-ui.icon name="edit" size="size-4" data-action-icon="edit" />
    <span>Edit</span>
</button>
