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
<body class="min-h-screen overflow-x-hidden bg-app-bg text-text-main antialiased">
    @php
        $currentUser = auth()->user();
        $roleLabel = $currentUser->role->label();
        $userInitials = str($currentUser->full_name)->explode(' ')->filter()->map(fn ($part) => str($part)->substr(0, 1))->take(2)->implode('');
        $localHour = now(config('cims.display_timezone'))->hour;
        $greeting = match (true) {
            $localHour >= 5 && $localHour < 12 => 'Good Morning',
            $localHour >= 12 && $localHour < 18 => 'Good Afternoon',
            default => 'Good Evening',
        };
    @endphp
    <a href="#main-content" class="fixed left-3 top-3 z-[70] -translate-y-20 rounded-control bg-surface px-4 py-2 font-semibold text-brand-primary shadow-float focus:translate-y-0">Skip to main content</a>

    <div data-mobile-backdrop data-drawer-close class="ui-backdrop" hidden></div>

    <aside data-mobile-drawer id="primary-sidebar" class="ui-scrollbar fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col overflow-y-auto bg-brand-sidebar text-white shadow-float transition-transform duration-200 lg:translate-x-0 lg:shadow-none" aria-label="Primary navigation">
        <div class="flex min-h-20 items-center justify-between border-b border-white/10 px-5">
            <a href="{{ route('home') }}" class="min-w-0 flex-1 rounded-control focus-visible:outline-white" aria-label="Binhi Rural Bank Inc. dashboard">
                <img src="{{ asset('assets/branding/binhi-rural-bank-wordmark-light.png') }}" alt="Binhi Rural Bank Inc." class="h-auto w-full max-w-[11.5rem] object-contain object-left">
            </a>
            <button type="button" data-drawer-close class="ui-icon-button text-white/70 hover:bg-white/10 hover:text-white lg:hidden" aria-label="Close navigation"><x-ui.icon name="close" /></button>
        </div>

        <nav class="flex-1 space-y-6 px-4 py-6">
            <div>
                <p class="mb-2 px-3 text-[0.68rem] font-bold uppercase tracking-[0.18em] text-white/45">Workspace</p>
                <div class="space-y-1">
                    <x-ui.sidebar-link :href="route('home')" icon="dashboard" :active="request()->routeIs('home')">Dashboard</x-ui.sidebar-link>
                    <x-ui.sidebar-link :href="route('client-folders.index')" icon="folder" :active="request()->routeIs('client-folders.*')">Client Folders</x-ui.sidebar-link>
                    <x-ui.sidebar-link :href="route('ci-activities.index')" icon="activity" :active="request()->routeIs('ci-activities.*')">CI Activities</x-ui.sidebar-link>
                    <x-ui.sidebar-link :href="route('reports.index')" icon="report" :active="request()->routeIs('reports.*')">Reports</x-ui.sidebar-link>
                    <x-ui.sidebar-link :href="route('media.index')" icon="media" :active="request()->routeIs('media.*')">Photos &amp; Videos</x-ui.sidebar-link>
                </div>
            </div>
            <div>
                <p class="mb-2 px-3 text-[0.68rem] font-bold uppercase tracking-[0.18em] text-white/45">Integrations &amp; records</p>
                <div class="space-y-1">
                    <x-ui.sidebar-link :href="route('telegram.index')" icon="telegram" :active="request()->routeIs('telegram.*')">Telegram History</x-ui.sidebar-link>
                    <x-ui.sidebar-link :href="route('drive.index')" icon="drive" :active="request()->routeIs('drive.*')">Google Drive</x-ui.sidebar-link>
                    <x-ui.sidebar-link :href="route('recycle-bin.index')" icon="trash" :active="request()->routeIs('recycle-bin.*')">Recycle Bin</x-ui.sidebar-link>
                </div>
            </div>
            @can('viewAny', App\Models\User::class)
                <div>
                    <p class="mb-2 px-3 text-[0.68rem] font-bold uppercase tracking-[0.18em] text-white/45">Administration</p>
                    <div class="space-y-1">
                        <x-ui.sidebar-link :href="route('admin.users.index')" icon="users" :active="request()->routeIs('admin.users.*')">Users</x-ui.sidebar-link>
                        @can('viewAny', App\Models\SystemSetting::class)<x-ui.sidebar-link :href="route('admin.settings.index')" icon="settings" :active="request()->routeIs('admin.settings.*')">Settings</x-ui.sidebar-link>@endcan
                        @can('viewAny', App\Models\AuditLog::class)<x-ui.sidebar-link :href="route('admin.audit-logs.index')" icon="audit" :active="request()->routeIs('admin.audit-logs.*')">Audit Trail</x-ui.sidebar-link>@endcan
                    </div>
                </div>
            @endcan
        </nav>

        <div class="flex items-center px-4 py-3 text-left">
            <p class="whitespace-normal text-[0.7rem] font-normal leading-4 text-white/50">&copy; 2026 <span class="font-medium text-white/65">Binhi Rural Bank Inc.</span> All Rights Reserved.</p>
        </div>
    </aside>

    <div class="min-h-screen lg:pl-64">
        <header class="sticky top-0 z-30 border-b border-ui-border bg-surface/95 backdrop-blur">
            <div class="flex min-h-16 items-center justify-between gap-3 px-4 py-2 sm:px-6 lg:px-8">
                <div class="flex min-w-0 items-center gap-3">
                    <button type="button" data-drawer-toggle class="ui-icon-button -ml-2 lg:hidden" aria-controls="primary-sidebar" aria-expanded="false" aria-label="Open navigation"><x-ui.icon name="menu" /></button>
                    <p class="min-w-0 text-sm font-semibold leading-5 tracking-[-0.01em] sm:text-base lg:text-[1.05rem]">{{ $greeting }}, {{ $currentUser->full_name }}</p>
                </div>
                <x-ui.context-menu label="Open account menu" class="shrink-0">
                    <x-slot:trigger>
                        <span class="flex min-h-11 items-center gap-2 px-1.5 py-1 transition hover:bg-brand-soft sm:gap-3 sm:px-2">
                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-primary text-xs font-bold text-white" aria-hidden="true">{{ $userInitials }}</span>
                            <span class="hidden min-w-0 text-left sm:block">
                                <span class="block max-w-44 truncate text-sm font-bold text-text-main">{{ $currentUser->full_name }}</span>
                                <span class="block text-xs text-text-muted">{{ $roleLabel }}</span>
                            </span>
                            <x-ui.icon name="chevron-down" size="size-4" class="text-text-muted" />
                        </span>
                    </x-slot:trigger>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary" role="menuitem"><x-ui.icon name="logout" size="size-4" />Logout</button></form>
                </x-ui.context-menu>
            </div>
        </header>

        <main id="main-content" class="mx-auto w-full max-w-[100rem] px-4 py-4 sm:px-6 sm:py-5 lg:px-8" tabindex="-1">
            <div class="fixed right-4 top-20 z-[70] w-[calc(100%-2rem)] max-w-sm space-y-3 sm:right-6" data-toast-region aria-live="polite">
                @if(session('status'))<x-ui.toast type="success" :message="session('status')" />@endif
            </div>
            @yield('content')
        </main>
    </div>
</body>
</html>
