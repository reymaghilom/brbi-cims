@extends('layouts.app')

@section('title', 'Manage user')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'Users', 'url' => route('admin.users.index')], ['label' => $managedUser->full_name]]" />
    <x-ui.page-header :title="$managedUser->full_name" eyebrow="Manage user">
        <x-slot:description>{{ $managedUser->username }} · {{ str($managedUser->role->value)->replace('_', ' ')->title() }}</x-slot:description>
        <x-slot:actions><x-ui.status-badge :status="$managedUser->status" /></x-slot:actions>
    </x-ui.page-header>

    @if (session('temporary_password'))
        <section class="mb-6 rounded-card border border-progress/30 bg-progress-soft p-5 text-text-main" role="status" aria-labelledby="temporary-password-title">
            <div class="flex items-start gap-3"><x-ui.icon name="warning" class="text-progress" /><div class="min-w-0"><h2 id="temporary-password-title" class="font-bold">Temporary password — display once</h2><p class="mt-3 select-all break-all rounded-control bg-surface px-4 py-3 font-mono text-lg shadow-sm">{{ session('temporary_password') }}</p><p class="mt-2 text-sm text-text-muted">Provide this securely to the user. It is not stored in audit logs and will not be shown again.</p></div></div>
        </section>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <form method="POST" action="{{ route('admin.users.update', $managedUser) }}" enctype="multipart/form-data">
            @csrf @method('PUT')
            <x-ui.form-section title="Account information" description="Update basic identity and role information.">@include('admin.users._form')</x-ui.form-section>
            <x-ui.sticky-form-toolbar>Role changes take effect after session invalidation.<x-slot:actions><button class="ui-button-primary">Save changes</button></x-slot:actions></x-ui.sticky-form-toolbar>
        </form>

        <aside class="space-y-6">
            <section class="ui-panel p-5"><h2 class="ui-section-title">Security status</h2><dl class="mt-4 space-y-3 text-sm"><div class="flex justify-between gap-3"><dt class="text-text-muted">Last login</dt><dd class="text-right font-semibold">{{ $managedUser->last_login_at?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? 'Never' }}</dd></div><div class="flex justify-between gap-3"><dt class="text-text-muted">Password change</dt><dd class="text-right font-semibold">{{ $managedUser->must_change_password ? 'Required' : 'Not required' }}</dd></div></dl></section>

            @if (! auth()->user()->is($managedUser))
                <section class="ui-panel p-5"><h2 class="ui-section-title">Account actions</h2><p class="mt-2 text-sm leading-6 text-text-muted">Security actions invalidate the affected user's existing sessions.</p><div class="mt-5 grid gap-3">
                    <button id="status-action" type="button" data-modal-open="status-dialog" class="{{ $managedUser->status === App\Enums\UserStatus::Active ? 'ui-button-danger' : 'ui-button-primary' }}">{{ $managedUser->status === App\Enums\UserStatus::Active ? 'Disable account' : 'Activate account' }}</button>
                    <button id="reset-password-action" type="button" data-modal-open="reset-password-dialog" class="ui-button-secondary">Reset password</button>
                </div></section>

                <x-ui.confirmation-dialog id="status-dialog" :title="$managedUser->status === App\Enums\UserStatus::Active ? 'Disable this account?' : 'Activate this account?'" :action="route('admin.users.status.update', $managedUser)" method="PATCH" :confirm-label="$managedUser->status === App\Enums\UserStatus::Active ? 'Disable account' : 'Activate account'" :destructive="$managedUser->status === App\Enums\UserStatus::Active">
                    <x-slot:formFields><input type="hidden" name="status" value="{{ $managedUser->status === App\Enums\UserStatus::Active ? 'disabled' : 'active' }}"></x-slot:formFields>
                    <p>{{ $managedUser->status === App\Enums\UserStatus::Active ? 'The user will be signed out and prevented from signing in.' : 'The user will be permitted to sign in again.' }}</p>
                </x-ui.confirmation-dialog>
                <x-ui.confirmation-dialog id="reset-password-dialog" title="Reset this user's password?" :action="route('admin.users.password.reset', $managedUser)" confirm-label="Reset password"><p>A temporary password will be generated and shown once. The user will be signed out and required to create a new password.</p></x-ui.confirmation-dialog>
            @endif
        </aside>
    </div>
@endsection
