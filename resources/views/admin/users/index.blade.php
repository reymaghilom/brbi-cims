@extends('layouts.app')

@section('title', 'Users')

@section('content')
    @php
        $requestedModalUser = old('user_modal') === 'edit'
            ? $users->getCollection()->firstWhere('id', (int) old('user_modal_id'))
            : null;
        $userModalMode = $requestedModalUser ? 'edit' : 'create';
        $userModalHasErrors = in_array(old('user_modal'), ['create', 'edit'], true);
    @endphp

    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'Users']]" />
    <x-ui.page-header title="Users" eyebrow="Administration">
        <x-slot:description>Manage authorized BRBI CIMS staff accounts. Accounts are disabled rather than deleted to preserve historical records.</x-slot:description>
        <x-slot:actions>
            <button type="button" class="ui-button-primary" data-modal-open="user-form-dialog" data-user-form-trigger data-user-form-mode="create" data-user-form-action="{{ route('admin.users.store') }}">
                <x-ui.icon name="users" />Create user
            </button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('temporary_password'))
        <section class="mb-6 rounded-card border border-progress/30 bg-progress-soft p-5 text-text-main" role="status" aria-labelledby="temporary-password-title">
            <div class="flex items-start gap-3">
                <x-ui.icon name="warning" class="text-progress" />
                <div class="min-w-0">
                    <h2 id="temporary-password-title" class="font-bold">Temporary password — display once</h2>
                    <p class="mt-3 select-all break-all rounded-control bg-surface px-4 py-3 font-mono text-lg shadow-sm">{{ session('temporary_password') }}</p>
                    <p class="mt-2 text-sm text-text-muted">Provide this securely to the user. It is not stored in audit logs and will not be shown again.</p>
                </div>
            </div>
        </section>
    @endif

    <div class="ui-table-wrap hidden md:block">
        <table class="ui-table">
            <thead><tr><th scope="col">Staff member</th><th scope="col">Email Address</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
                @foreach ($users as $managedUser)
                    <tr>
                        <td><div class="flex items-center gap-3">@if ($managedUser->profilePhotoUrl())<img src="{{ $managedUser->profilePhotoUrl() }}" alt="" class="size-9 shrink-0 rounded-full border border-ui-border object-cover [aspect-ratio:1/1]">@else<span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-soft font-bold text-brand-primary">{{ str($managedUser->full_name)->substr(0, 1)->upper() }}</span>@endif<div><p class="font-bold">{{ $managedUser->full_name }}</p></div></div></td>
                        <td class="font-medium" data-user-email-display>{{ $managedUser->email ?: '—' }}</td>
                        <td>{{ $managedUser->role->label() }}</td>
                        <td><x-ui.status-badge :status="$managedUser->status" /></td>
                        <td class="text-right">@include('admin.users._manage-modal-trigger', ['managedUser' => $managedUser])</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="grid gap-4 md:hidden">
        @forelse($users as $managedUser)
            <article class="ui-card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">@if ($managedUser->profilePhotoUrl())<img src="{{ $managedUser->profilePhotoUrl() }}" alt="" class="size-9 shrink-0 rounded-full border border-ui-border object-cover [aspect-ratio:1/1]">@else<span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-soft font-bold text-brand-primary">{{ str($managedUser->full_name)->substr(0, 1)->upper() }}</span>@endif<div class="min-w-0"><h2 class="truncate font-bold">{{ $managedUser->full_name }}</h2><p class="mt-1 break-all text-sm text-text-muted" data-user-email-display><span class="font-semibold">Email Address:</span> {{ $managedUser->email ?: '—' }}</p></div></div>
                    <x-ui.status-badge :status="$managedUser->status" />
                </div>
                <div class="mt-4 flex items-center justify-between border-t border-ui-border pt-4">
                    <span class="text-sm font-semibold text-text-muted">{{ $managedUser->role->label() }}</span>
                    @include('admin.users._manage-modal-trigger', ['managedUser' => $managedUser])
                </div>
            </article>
        @empty
            <x-ui.empty-state title="No users found" icon="users" />
        @endforelse
    </div>

    <div class="mt-6">{{ $users->links() }}</div>

    @include('admin.users._modal', [
        'managedUser' => $requestedModalUser,
        'modalMode' => $userModalMode,
        'openOnError' => $userModalHasErrors,
    ])
@endsection
