@extends('layouts.auth')

@section('title', 'Reset password')

@section('auth-introduction')
    <p class="mt-6 text-center text-sm font-semibold leading-6 text-slate-700 sm:text-base">Credit Investigation Management System</p>
@endsection

@section('content')
    <div>
        <h1 class="text-xl font-semibold tracking-tight text-text-main">Reset password</h1>
        <p class="mt-1 text-sm leading-5 text-text-muted">Enter your email address.</p>
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-control border border-brand-primary/20 bg-brand-soft px-3.5 py-3 text-sm leading-5 text-brand-sidebar" role="status">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-5 space-y-4">
        @csrf
        <div>
            <label for="email" class="ui-label uppercase tracking-wide text-text-muted">Email Address <span class="text-danger" aria-hidden="true">*</span><span class="sr-only">required</span></label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" inputmode="email" class="ui-control min-h-12" @if($errors->passwordReset->has('email')) aria-invalid="true" aria-describedby="email-error" @endif>
            @error('email', 'passwordReset')
                <p id="email-error" class="mt-2 flex items-start gap-1.5 text-sm font-medium text-danger" role="alert"><x-ui.icon name="warning" size="mt-0.5 size-4" />{{ $message }}</p>
            @enderror
        </div>
        <button type="submit" class="ui-button-primary w-full"><x-ui.icon name="mail" size="size-4" data-action-icon="mail" />Send reset link</button>
    </form>

    <p class="mt-4 text-center text-sm">
        <a href="{{ route('login') }}" class="font-semibold text-brand-primary hover:text-brand-primary-hover">Back to sign in</a>
    </p>
@endsection
