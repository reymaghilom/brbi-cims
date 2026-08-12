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
<body class="min-h-screen bg-surface text-text-main antialiased">
    <main class="flex min-h-screen items-center justify-center px-4 py-8 sm:px-6" aria-label="BRBI secure authentication">
        <section class="w-full max-w-[27rem] rounded-panel border border-white/20 bg-surface p-6 shadow-float sm:p-8">
            <img src="{{ asset('assets/branding/binhi-rural-bank-wordmark.png') }}" alt="Binhi Rural Bank Inc." class="mx-auto h-auto w-full max-w-[14rem] object-contain">
            <p class="mx-auto mt-4 max-w-sm text-center text-[1.05rem] font-semibold leading-6 text-brand-primary">Credit Investigation Management System</p>
            <div class="mt-6">@yield('content')</div>
        </section>
    </main>
</body>
</html>
