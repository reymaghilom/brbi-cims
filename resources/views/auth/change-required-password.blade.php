@extends('layouts.auth')

@section('title', 'Change password')

@section('content')
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-progress">Security required</p>
        <h2 class="mt-2 text-2xl font-bold tracking-tight">Create a new password</h2>
        <p class="mt-2 text-sm leading-6 text-text-muted">Replace your temporary password before continuing. Your new password must contain at least 12 characters.</p>
    </div>

    <form method="POST" action="{{ route('password.change-required.update') }}" class="mt-7 space-y-5">
        @csrf
        @method('PUT')
        <x-form.input name="current_password" label="Temporary password" type="password" required autocomplete="current-password" />
        <x-form.input name="password" label="New password" type="password" help="Use at least 12 characters." required minlength="12" autocomplete="new-password" />
        <x-form.input name="password_confirmation" label="Confirm new password" type="password" required minlength="12" autocomplete="new-password" />
        <button type="submit" class="ui-button-primary w-full">Change password and continue</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-3">
        @csrf
        <button class="ui-button-secondary w-full">Sign out instead</button>
    </form>
@endsection
