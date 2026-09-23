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
    <main class="flex min-h-screen items-center justify-center px-4 py-8 sm:px-6 sm:py-12" aria-label="BRBI secure authentication">
        <div class="w-full max-w-[28rem]">
            <section class="rounded-panel border border-ui-border bg-surface p-6 shadow-float sm:p-9">
                <img src="{{ asset('assets/branding/binhi-rural-bank-wordmark.png') }}" alt="Binhi Rural Bank Inc." class="mx-auto h-auto w-full max-w-[14rem] object-contain">
                @hasSection('auth-introduction')
                    @yield('auth-introduction')
                @else
                    <p class="mx-auto mt-4 max-w-sm text-center text-[1.05rem] font-semibold leading-6 text-brand-sidebar">Credit Investigation Management System</p>
                @endif
                <div class="mt-7">@yield('content')</div>
            </section>

            @hasSection('auth-footer')
                <footer class="px-3 pt-5 text-center text-xs leading-5 text-text-muted">
                    @yield('auth-footer')
                </footer>
            @endif
        </div>
    </main>
</body>
</html>
