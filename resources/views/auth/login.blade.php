@extends('layouts.auth')

@section('title', 'Sign in')

@section('content')
    @error('authentication')
        <div class="flex items-start gap-2.5 rounded-control border border-danger/25 bg-danger-soft px-3.5 py-3 text-[0.8rem] font-medium leading-5 text-danger" role="alert" aria-live="polite">
            <x-ui.icon name="warning" size="mt-0.5 size-4" />
            <p>{{ $message }}</p>
        </div>
    @enderror

    <form method="POST" action="{{ route('login.store') }}" @class(['space-y-4', 'mt-4' => $errors->has('authentication')])>
        @csrf
        <x-form.input name="username" label="Username" :value="old('username')" required autofocus autocomplete="username" />
        <x-form.input name="password" label="Password" type="password" required autocomplete="current-password" />

        <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-control text-sm font-medium text-text-muted">
            <input name="remember" type="checkbox" value="1" class="size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary" @checked(old('remember'))>
            Remember me
        </label>

        <button type="submit" class="ui-button-primary w-full">Login</button>
    </form>
@endsection
