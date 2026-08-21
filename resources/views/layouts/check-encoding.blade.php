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
<body class="min-h-screen overflow-x-hidden bg-app-bg text-text-main antialiased" data-check-encoding-layout>
    <main class="mx-auto w-full max-w-6xl px-3 py-4 sm:px-5 sm:py-5">
        <div class="fixed right-4 top-4 z-[70] w-[calc(100%-2rem)] max-w-sm space-y-3 sm:right-6" data-toast-region aria-live="polite">
            @if(session('status'))<x-ui.toast type="success" :message="session('status')" />@endif
        </div>
        @if(session('status') && isset($clientFolder))
            <span hidden data-check-saved-notify data-check-saved-return-url="{{ route('client-folders.residence-business.edit', [$clientFolder] + \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null)) }}"></span>
        @endif

        @yield('content')
    </main>
</body>
</html>
