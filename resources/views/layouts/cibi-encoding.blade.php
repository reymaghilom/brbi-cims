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
<body class="min-h-screen overflow-x-hidden bg-app-bg text-text-main antialiased" data-cibi-standalone-layout>
    <a href="#cibi-encoding-main" class="fixed left-3 top-3 z-[70] -translate-y-20 rounded-control bg-surface px-4 py-2 font-semibold text-brand-primary shadow-float focus:translate-y-0">Skip to CI / BI form</a>

    <main id="cibi-encoding-main" class="cibi-standalone-main mx-auto w-full max-w-[120rem] px-2 py-3 sm:px-4 sm:py-4 lg:px-6 lg:py-5" tabindex="-1">
        <div class="fixed right-4 top-4 z-[70] w-[calc(100%-2rem)] max-w-sm space-y-3 sm:right-6" data-toast-region aria-live="polite">
            @if(session('status'))<x-ui.toast type="success" :message="session('status')" />@endif
        </div>

        @yield('content')
    </main>
</body>
</html>
