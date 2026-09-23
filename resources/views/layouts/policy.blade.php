<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @include('partials.favicon')
    <title>@yield('title') · BRBI CIMS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-app-bg text-text-main antialiased">
    <main class="px-4 py-7 sm:px-6 sm:py-10" aria-labelledby="policy-title">
        <div class="mx-auto w-full max-w-5xl">
            <header class="flex flex-col gap-5 border-b border-ui-border pb-6 sm:flex-row sm:items-center sm:justify-between">
                <a href="{{ route('login') }}" class="w-fit rounded-control focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2" aria-label="BRBI CIMS sign in">
                    <img src="{{ asset('assets/branding/binhi-rural-bank-wordmark.png') }}" alt="Binhi Rural Bank Inc." class="h-auto w-full max-w-[12rem] object-contain">
                </a>
                <a href="{{ route('login') }}" class="inline-flex min-h-11 w-fit items-center gap-2 rounded-control px-1 text-sm font-semibold text-brand-primary hover:text-brand-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2">
                    <x-ui.icon name="chevron-right" size="size-4 rotate-180" />
                    Back to Sign in
                </a>
            </header>

            <div class="py-8 sm:py-10">
                <div class="max-w-3xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.16em] text-brand-primary">BRBI CIMS</p>
                    <h1 id="policy-title" class="mt-2 text-3xl font-bold tracking-tight text-brand-sidebar sm:text-4xl">@yield('title')</h1>
                    <p class="mt-4 text-base leading-7 text-text-muted">@yield('introduction')</p>
                </div>

                <div class="mt-8 space-y-4 sm:space-y-5">
                    @yield('content')
                </div>
            </div>

            <footer class="flex flex-col gap-3 border-t border-ui-border py-6 text-sm text-text-muted sm:flex-row sm:items-center sm:justify-between">
                <p>Version 1.0</p>
                @yield('cross-link')
            </footer>
        </div>
    </main>
</body>
</html>
