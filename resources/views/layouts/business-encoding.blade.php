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
            {{--
                data-business-saved-payload carries the same authoritative Saved Businesses /
                Recent Activity / candidates / View-All-modal fragments IncomeSourceController's
                refreshPayload() renders for the dedicated delete endpoints — flashed onto the
                session by afterSave()/store() so this landing page (the "stay" redirect target,
                loaded inside the parent's existing modal/iframe) can hand it straight to the
                parent via postMessage. This is what lets a save AUTO-UPDATE the parent page
                without the parent ever issuing its own follow-up GET request.
            --}}
            <span hidden data-business-saved-notify data-business-saved-return-url="{{ route('client-folders.income-sources.manage', [$clientFolder] + \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null)) }}" data-business-saved-payload="{{ json_encode(session('business_manage_refresh')) }}" data-business-saved-message="{{ session('status') }}" data-business-saved-status-type="{{ session('statusType', 'success') }}"></span>
        @endif

        @yield('content')
    </main>
</body>
</html>
