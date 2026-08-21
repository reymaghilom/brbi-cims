@extends('layouts.app')

@section('title', 'Create user')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'Users', 'url' => route('admin.users.index')], ['label' => 'Create user']]" />
    <x-ui.page-header title="Create user" eyebrow="Administration"><x-slot:description>Create an authorized staff account with a temporary password that must be changed after sign-in.</x-slot:description></x-ui.page-header>

    <form method="POST" action="{{ route('admin.users.store') }}" enctype="multipart/form-data" class="max-w-3xl">
        @csrf
        <x-ui.form-section title="Account information" description="Enter the staff member's identifying information and approved system role.">
            @include('admin.users._form')
        </x-ui.form-section>
        <x-ui.form-section title="Temporary password" description="The user will be required to replace this password after their first successful sign-in." class="mt-6">
            <x-form.input name="password" label="Temporary password" type="password" required minlength="12" autocomplete="new-password" help="Use at least 12 characters." />
            <x-form.input name="password_confirmation" label="Confirm temporary password" type="password" required minlength="12" autocomplete="new-password" />
        </x-ui.form-section>
        <x-ui.sticky-form-toolbar>Passwords are stored only as secure hashes.<x-slot:actions><a href="{{ route('admin.users.index') }}" class="ui-button-secondary">Cancel</a><button class="ui-button-primary">Create user</button></x-slot:actions></x-ui.sticky-form-toolbar>
    </form>
@endsection
