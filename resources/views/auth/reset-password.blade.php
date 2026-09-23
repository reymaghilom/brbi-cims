@extends('layouts.auth')

@section('title', 'Reset password')

@section('auth-introduction')
    <p class="mt-6 text-center text-sm font-semibold leading-6 text-slate-700 sm:text-base">Credit Investigation Management System</p>
@endsection

@section('content')
    <h1 class="text-xl font-semibold tracking-tight text-text-main">Reset password</h1>

    <form method="POST" action="{{ route('password.update') }}" class="mt-5 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-form.input name="email" label="Email Address" type="email" :value="old('email', $email)" required readonly autocomplete="email" class="[&_.ui-control]:min-h-12 [&_.ui-label]:uppercase [&_.ui-label]:tracking-wide [&_.ui-label]:text-text-muted" />
        <x-form.input name="password" label="New Password" type="password" required autofocus minlength="{{ \App\Support\Authentication\PasswordPolicy::MIN_LENGTH }}" autocomplete="new-password" class="[&_.ui-control]:min-h-12 [&_.ui-label]:uppercase [&_.ui-label]:tracking-wide [&_.ui-label]:text-text-muted" />
        <x-form.input name="password_confirmation" label="Confirm Password" type="password" required minlength="{{ \App\Support\Authentication\PasswordPolicy::MIN_LENGTH }}" autocomplete="new-password" class="[&_.ui-control]:min-h-12 [&_.ui-label]:uppercase [&_.ui-label]:tracking-wide [&_.ui-label]:text-text-muted" />
        <button type="submit" class="ui-button-primary w-full"><x-ui.icon name="key" size="size-4" data-action-icon="key" />Reset password</button>
    </form>
@endsection
