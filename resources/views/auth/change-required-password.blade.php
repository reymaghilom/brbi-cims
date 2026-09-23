@extends('layouts.auth')

@section('title', 'Change password')

@section('auth-introduction')
    <p class="mt-6 text-center text-sm font-semibold leading-6 text-slate-700 sm:text-base">Credit Investigation Management System</p>
@endsection

@section('content')
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-progress">Security required</p>
        <h2 class="mt-2 text-2xl font-bold tracking-tight">Create a new password</h2>
        <p class="mt-2 text-sm leading-6 text-text-muted">Replace your temporary password before continuing. Your new password must contain at least {{ \App\Support\Authentication\PasswordPolicy::MIN_LENGTH }} characters.</p>
    </div>

    <form method="POST" action="{{ route('password.change-required.update') }}" class="mt-7 space-y-5">
        @csrf
        @method('PUT')
        <x-form.input name="current_password" label="Temporary password" type="password" required autocomplete="current-password" />
        <x-form.input name="password" label="New password" type="password" help="Use at least {{ \App\Support\Authentication\PasswordPolicy::MIN_LENGTH }} characters." required minlength="{{ \App\Support\Authentication\PasswordPolicy::MIN_LENGTH }}" autocomplete="new-password" />
        <x-form.input name="password_confirmation" label="Confirm new password" type="password" required minlength="{{ \App\Support\Authentication\PasswordPolicy::MIN_LENGTH }}" autocomplete="new-password" />
        <button type="submit" class="ui-button-primary w-full">
            <x-ui.icon name="key" size="size-4" data-action-icon="key" />
            <span>Change password</span>
        </button>
    </form>
@endsection
