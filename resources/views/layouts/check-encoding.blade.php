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
{{-- overflow-x-clip, not -hidden: per the CSS overflow spec, explicitly hiding one axis while
     the other is left "visible" forces the other axis to compute as "auto" instead — i.e.
     overflow-x-hidden here silently turned <body> into a Y-axis clipping/scroll container too,
     which was clipping Residence Check's Google Places autocomplete suggestion dropdown
     (positioned relative to an unpositioned ancestor, it climbed all the way up to body). `clip`
     doesn't carry that same-axis-coupling quirk, so it blocks horizontal overflow exactly like
     `hidden` did without capturing vertical overflow into a clipping box. --}}
<body class="min-h-screen overflow-x-clip bg-app-bg text-text-main antialiased" data-check-encoding-layout>
    <main class="mx-auto w-full max-w-6xl px-3 py-4 sm:px-5 sm:py-5">
        <div class="fixed right-4 top-4 z-[70] w-[calc(100%-2rem)] max-w-sm space-y-3 sm:right-6" data-toast-region aria-live="polite">
            @if(session('status'))<x-ui.toast :type="session('statusType', 'success')" :message="session('status')" />@endif
        </div>
        @if(session('status') && isset($clientFolder) && session('statusType', 'success') === 'success')
            {{-- Reflashing lets this same status survive one more request: the parent window's
                 close-triggered reload of the Residence & Business Checks listing below, so the
                 success toast still shows there instead of only flashing briefly inside the modal. --}}
            @php(session()->keep(['status', 'statusType']))
            <span hidden data-check-saved-notify data-check-saved-return-url="{{ route('client-folders.residence-business.edit', [$clientFolder] + \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null)) }}"></span>
        @endif

        @yield('content')
    </main>
</body>
</html>
