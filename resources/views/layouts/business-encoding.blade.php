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
<body class="min-h-screen overflow-x-hidden bg-app-bg text-text-main antialiased" data-business-encoding-layout>
    <a href="#business-report-form" class="fixed left-3 top-3 z-[70] -translate-y-20 rounded-control bg-surface px-4 py-2 font-semibold text-brand-primary shadow-float focus:translate-y-0">Skip to Business Report form</a>

    <main class="business-standalone-main mx-auto w-full max-w-[120rem] px-2 py-3 sm:px-4 sm:py-4 lg:px-6 lg:py-5">
        <div class="fixed right-4 top-4 z-[70] w-[calc(100%-2rem)] max-w-sm space-y-3 sm:right-6" data-toast-region aria-live="polite">
            @if(session('status'))<x-ui.toast type="success" :message="session('status')" />@endif
        </div>
        @if(session('status') && isset($clientFolder))
            <span hidden data-business-saved-notify data-business-saved-return-url="{{ route('client-folders.income-sources.manage', [$clientFolder] + \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null)) }}"></span>
        @endif

        @yield('content')
    </main>
</body>
</html>
