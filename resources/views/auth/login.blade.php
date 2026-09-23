@extends('layouts.auth')

@section('title', 'Sign in')

@section('auth-introduction')
    <p class="mt-6 text-center text-sm font-semibold leading-6 text-slate-700 sm:text-base">Credit Investigation Management System</p>
@endsection

@section('content')
    @if (session('status'))
        <div class="flex items-start gap-2.5 rounded-control border border-brand-primary/20 bg-brand-soft px-3.5 py-3 text-sm leading-5 text-brand-sidebar" role="status" aria-live="polite">
            <x-ui.icon name="check-circle" size="mt-0.5 size-4 shrink-0 text-brand-primary" />
            <p>{{ session('status') }}</p>
        </div>
    @endif

    @error('authentication')
        <div class="flex items-start gap-2.5 rounded-control border border-danger/25 bg-danger-soft px-3.5 py-3 text-[0.8rem] font-medium leading-5 text-danger" role="alert" aria-live="polite">
            <x-ui.icon name="warning" size="mt-0.5 size-4" />
            <p>{{ $message }}</p>
        </div>
    @enderror

    <form method="POST" action="{{ route('login.store') }}" data-login-form data-submit-guard @class(['space-y-5', 'mt-5' => $errors->has('authentication') || session()->has('status')])>
        @csrf
        <x-form.input name="email" label="Email" type="email" :value="old('email')" required autofocus autocomplete="email" inputmode="email" class="[&_.ui-control]:min-h-12 [&_.ui-label]:uppercase [&_.ui-label]:tracking-wide [&_.ui-label]:text-text-muted" />
        <div>
            <label for="password" class="ui-label uppercase tracking-wide text-text-muted">Password <span class="text-danger" aria-hidden="true">*</span><span class="sr-only">required</span></label>
            <div class="relative">
                <input id="password" name="password" type="password" required autocomplete="current-password" class="ui-control min-h-12 pr-12" @if($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif>
                <button type="button" class="ui-icon-button absolute right-0.5 top-1/2 -translate-y-1/2" aria-label="Show password" aria-controls="password" aria-pressed="false" data-password-visibility-toggle>
                    <span data-password-visible-icon><x-ui.icon name="eye" /></span>
                    <span data-password-hidden-icon hidden><x-ui.icon name="eye-off" /></span>
                </button>
            </div>
            <x-form.validation-message for="password" />
        </div>

        <div class="flex min-h-11 flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <label class="flex cursor-pointer items-center gap-3 rounded-control text-sm font-medium text-text-muted">
                <input name="remember" type="checkbox" value="1" class="size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary" @checked(old('remember'))>
                Remember me
            </label>
            <a href="{{ route('password.request') }}" data-modal-open="forgot-password-dialog" aria-haspopup="dialog" class="text-sm font-semibold text-brand-primary hover:text-brand-primary-hover">Forgot password?</a>
        </div>

        <button type="submit" class="ui-button-primary w-full" data-login-submit>
            <span class="inline-flex items-center gap-2" data-login-ready>
                <x-ui.icon name="login" size="size-4" data-action-icon="login" />
                <span>Sign in</span>
            </span>
            <span class="inline-flex items-center gap-2" data-login-loading hidden>
                <svg class="size-4 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true" data-login-spinner>
                    <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" />
                    <path class="opacity-90" fill="currentColor" d="M12 3a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6V3Z" />
                </svg>
                <span>Signing in...</span>
            </span>
        </button>
    </form>

    <x-ui.modal id="forgot-password-dialog" title="Reset password" description="We'll email a link to the address on your account. Open it to set a new password." size="max-w-md" class="[&_h2]:font-semibold" data-open-on-error="{{ $errors->passwordReset->isNotEmpty() ? 'true' : 'false' }}">
        <form id="forgot-password-form" method="POST" action="{{ route('password.email') }}">
            @csrf
            <label for="reset-email" class="ui-label uppercase tracking-wide text-text-muted">Email Address <span class="text-danger" aria-hidden="true">*</span><span class="sr-only">required</span></label>
            <input id="reset-email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" inputmode="email" autofocus class="ui-control min-h-12" @if($errors->passwordReset->has('email')) aria-invalid="true" aria-describedby="reset-email-error" @endif>
            @error('email', 'passwordReset')
                <p id="reset-email-error" class="mt-2 flex items-start gap-1.5 text-sm font-medium text-danger" role="alert"><x-ui.icon name="warning" size="mt-0.5 size-4" />{{ $message }}</p>
            @enderror
        </form>
        <x-slot:footer>
            <button type="button" data-modal-close class="ui-button-secondary w-full sm:w-auto"><x-ui.icon name="close" size="size-4" data-action-icon="close" />Cancel</button>
            <button type="submit" form="forgot-password-form" class="ui-button-primary w-full sm:w-auto"><x-ui.icon name="mail" size="size-4" data-action-icon="mail" />Send reset link</button>
        </x-slot:footer>
    </x-ui.modal>
@endsection

@section('auth-footer')
    <p>
        For authorized personnel only. By signing in, you agree to the
        <a id="acceptable-use-policy" href="{{ route('policies.acceptable-use') }}" class="font-medium text-brand-primary underline decoration-brand-primary/35 underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2">Acceptable Use Policy</a>.
        See how your information is handled in the
        <a id="privacy-notice" href="{{ route('policies.privacy') }}" class="font-medium text-brand-primary underline decoration-brand-primary/35 underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2">Privacy Notice</a>.
    </p>
@endsection
