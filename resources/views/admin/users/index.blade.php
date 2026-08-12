@extends('layouts.app')

@section('title', 'Users')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'Users']]" />
    <x-ui.page-header title="Users" eyebrow="Administration">
        <x-slot:description>Manage authorized BRBI CIMS staff accounts. Accounts are disabled rather than deleted to preserve historical records.</x-slot:description>
        <x-slot:actions><a href="{{ route('admin.users.create') }}" class="ui-button-primary"><x-ui.icon name="users" />Create user</a></x-slot:actions>
    </x-ui.page-header>

    <div class="ui-table-wrap hidden md:block">
        <table class="ui-table">
            <thead><tr><th scope="col">Staff member</th><th scope="col">Username</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
                @foreach ($users as $managedUser)
                    <tr>
                        <td><div class="flex items-center gap-3"><span class="grid size-9 place-items-center rounded-full bg-brand-soft font-bold text-brand-primary">{{ str($managedUser->full_name)->substr(0, 1)->upper() }}</span><div><p class="font-bold">{{ $managedUser->full_name }}</p><p class="text-xs text-text-muted">{{ $managedUser->employee_id ?: 'No employee ID' }}</p></div></div></td>
                        <td class="font-medium">{{ $managedUser->username }}</td>
                        <td>{{ str($managedUser->role->value)->replace('_', ' ')->title() }}</td>
                        <td><x-ui.status-badge :status="$managedUser->status" /></td>
                        <td class="text-right"><a href="{{ route('admin.users.edit', $managedUser) }}" class="ui-button-secondary">Manage</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="grid gap-4 md:hidden">
        @forelse($users as $managedUser)
            <article class="ui-card p-5"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><h2 class="truncate font-bold">{{ $managedUser->full_name }}</h2><p class="mt-1 text-sm text-text-muted">{{ $managedUser->username }}</p></div><x-ui.status-badge :status="$managedUser->status" /></div><div class="mt-4 flex items-center justify-between border-t border-ui-border pt-4"><span class="text-sm font-semibold text-text-muted">{{ str($managedUser->role->value)->replace('_', ' ')->title() }}</span><a href="{{ route('admin.users.edit', $managedUser) }}" class="ui-button-secondary">Manage</a></div></article>
        @empty
            <x-ui.empty-state title="No users found" icon="users" />
        @endforelse
    </div>
    <div class="mt-6">{{ $users->links() }}</div>
@endsection
