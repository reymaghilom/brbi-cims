@php
    $folderBrowserAction = $folderBrowserAction ?? route('home');
    $folderBrowserContext = $folderBrowserContext ?? (request()->routeIs('home') ? 'dashboard' : 'client_folders');
    // Batched once for the whole listed page (not per folder) to keep the query count constant.
    // Dashboard Folder History shows ONLY the folder's own created/renamed lifecycle — recycle,
    // restore, permanent-delete, and every child-record module action (CI/BI, business reports,
    // photo uploads, etc.) are excluded here even though their audit rows remain untouched in
    // the database and still surface in the Admin Audit Log / Client Folder Contents' own
    // Recent Activity panel.
    $folderHistoryByFolder = \App\Models\AuditLog::query()
        ->whereIn('client_folder_id', $clientFolders->pluck('id'))
        ->whereIn('action', ['client_folder.created', 'client_folder.renamed'])
        ->with('user:id,full_name')
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->get(['id', 'client_folder_id', 'user_id', 'action', 'metadata', 'created_at'])
        ->groupBy('client_folder_id');
@endphp
{{-- Flash-prevention for the desktop Preview Panel collapse, same technique as the Photos &
     Videos support panel: read the persisted choice before first paint so the CSS below can
     suppress the wrong initial state — no show-then-hide (or hide-then-show) flash while app.js
     is still loading. --}}
<script>
    (function () {
        try {
            if (localStorage.getItem('brbi-folder-preview-collapsed') === 'true') {
                document.documentElement.setAttribute('data-folder-preview-collapsed', '');
            }
        } catch (e) {}
    })();
</script>
<style>
    @media (min-width: 1280px) {
        html[data-folder-preview-collapsed] [data-folder-browser-layout] { grid-template-columns: minmax(0, 1fr) 0px; }
        /* Also keep the panel out of the layout before first paint — a zeroed column alone still
           leaves it a grid item wrapping its content into a very tall invisible box, which would
           flash the page to that height until app.js takes over. */
        html[data-folder-preview-collapsed] [data-folder-preview-panel] { display: none; }
        html[data-folder-preview-collapsed] [data-folder-preview-show][hidden] { display: inline-flex !important; }
        html[data-folder-preview-collapsed] [data-folder-preview-hide] { display: none; }
    }
</style>
<section aria-label="Client folder browser" data-folder-browser>
    {{-- TOP TOOLBAR — its own card, a complete sibling of the animated layout below. It is not
         inside the main content container, not inside the animated grid, and not inside the
         preview container, so that grid's animated grid-template-columns can never resize or
         reposition any control in here. `min-w-0` throughout is deliberate: flex items default to `min-width: auto`, which
         — combined with the search's own intrinsic content width — is exactly what can force a
         flex row wider than its container and produce an unwanted horizontal scrollbar; every
         flexible piece here is explicitly allowed to shrink below its content's natural size
         instead. --}}
    <div class="ui-panel flex w-full min-w-0 flex-col gap-3 px-4 py-3 sm:px-5 sm:py-4 md:flex-row md:items-center md:gap-2" data-folder-toolbar data-dashboard-toolbar>
        {{-- Search fills all remaining toolbar width (flex: 1 1 0%, expressed here as flex-1)
             rather than a fixed pixel width — it starts flush left and stops naturally where
             Create Client Folder begins. `min-w-0` lets it actually shrink on narrower widths
             instead of forcing the row wider than the toolbar. --}}
        <div class="w-full min-w-0 md:flex-1" data-folder-toolbar-search data-dashboard-search>
            <form method="GET" action="{{ $folderBrowserAction }}" class="min-w-0 w-full" data-folder-browser-form data-client-search-form>
                <label for="folder-search" class="sr-only">Search client name</label>
                <div class="relative min-w-0" data-client-search data-live-search-url="{{ route('client-folders.live-search') }}" data-browser-context="{{ $folderBrowserContext }}">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-text-muted" aria-hidden="true"><x-ui.icon name="search" size="size-4" /></span>
                    <input id="folder-search" name="search" type="search" value="{{ $filters['search'] ?? '' }}" maxlength="150" class="ui-control min-h-10 w-full max-w-full py-2 pl-9 pr-10" placeholder="Search client name..." autocomplete="off" data-client-search-input>
                    <button type="button" class="absolute inset-y-0 right-1 my-auto inline-flex size-9 items-center justify-center rounded-control text-text-muted transition hover:bg-surface-muted hover:text-text-main" aria-label="Clear client search" data-client-search-clear @if(blank($filters['search'] ?? null)) hidden @endif><x-ui.icon name="close" size="size-4" /></button>
                </div>
            </form>
        </div>

        <div class="w-full shrink-0 md:w-auto" data-folder-toolbar-create data-dashboard-create>
            <button type="button" id="create-client-folder-trigger" class="ui-button-primary min-h-10 w-full px-4 py-2 md:w-auto" data-modal-open="create-client-folder-dialog">
                <span class="text-lg leading-none" aria-hidden="true">+</span>
                Create Client Folder
            </button>
        </div>

        {{-- Far right, after Create — the last flex child in a row that no longer has any
             auto-margin group to travel with; Search filling the remaining space already pins
             this to the end of the row. Two separate buttons — not one button with a swapped
             icon/label: it lets the pre-paint CSS above show the correct one immediately via a plain
             [hidden] attribute, with no JS needed to settle the initial label/icon before first
             paint. --}}
        <div class="hidden shrink-0 xl:block" data-folder-toolbar-preview-toggle data-dashboard-preview-toggle>
            <button type="button" class="ui-button-secondary-compact inline-flex min-h-10 px-3 py-2 text-sm" title="Show Panel" aria-label="Show Panel" aria-controls="folder-preview-panel" aria-expanded="false" data-folder-preview-show hidden>
                <x-ui.icon name="eye" size="size-3.5" />Show Panel
            </button>
            <button type="button" class="ui-button-secondary-compact inline-flex min-h-10 px-3 py-2 text-sm" title="Hide Panel" aria-label="Hide Panel" aria-controls="folder-preview-panel" aria-expanded="true" data-folder-preview-hide>
                <x-ui.icon name="eye-off" size="size-3.5" />Hide Panel
            </button>
        </div>
    </div>

    {{-- ANIMATED LAYOUT — a bare grid, not a card. Its only job is to size/animate its two
         children; the main content and the preview panel each carry their own .ui-panel card
         surface so they read as two separate containers with a real gap between them, never one
         merged card. --}}
    <div class="client-folder-browser-layout mt-3" data-folder-browser-layout data-dashboard-preview-layout>
        <div class="client-folder-results ui-panel min-w-0 p-4 sm:p-5" data-folder-results data-dashboard-main-container>
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm text-text-muted" aria-live="polite">
                        @if($clientFolders->total())
                            Showing {{ $clientFolders->firstItem() }}&ndash;{{ $clientFolders->lastItem() }} of {{ $clientFolders->total() }} authorized folders
                        @else
                            No authorized folders to display
                        @endif
                    </p>
                    <p class="hidden text-xs text-text-subtle sm:block">Select once for details · Double-click to open</p>
                </div>

                @if($clientFolders->isEmpty())
                    @php
                        $hasSearch = filled($filters['search'] ?? null);
                        $status = $filters['status'] ?? null;
                        $emptyTitle = match (true) {
                            $hasSearch => 'No folders match your search',
                            $status === 'on_progress' => 'No On Progress folders',
                            $status === 'completed' => 'No Completed folders',
                            default => 'No client folders yet',
                        };
                        $emptyDescription = match (true) {
                            $hasSearch => 'Try another client name or clear the current search.',
                            $status === 'on_progress' => 'No authorized folders currently have the On Progress status.',
                            $status === 'completed' => 'No authorized folders currently have the Completed status.',
                            default => 'Create the first client folder to begin an investigation record.',
                        };
                    @endphp
                    <x-ui.empty-state :title="$emptyTitle" :description="$emptyDescription" icon="folder">
                        <x-slot:action>
                            @if($hasSearch || $status)
                                <a href="{{ $folderBrowserAction }}" class="ui-button-secondary">Clear search and filters</a>
                            @else
                                <button type="button" id="empty-create-client-folder-trigger" class="ui-button-primary" data-modal-open="create-client-folder-dialog"><span aria-hidden="true">+</span>Create Client Folder</button>
                            @endif
                        </x-slot:action>
                    </x-ui.empty-state>
                @else
                    <div class="client-folder-grid" role="listbox" aria-label="Client folders">
                        @foreach($clientFolders as $clientFolder)
                            <div class="client-folder-tile-shell" data-folder-shell data-folder-id="{{ $clientFolder->id }}" data-folder-status="{{ $clientFolder->status->value }}">
                                <div
                                    id="client-folder-tile-{{ $clientFolder->id }}"
                                    class="client-folder-tile"
                                    role="option"
                                    tabindex="0"
                                    aria-selected="false"
                                    aria-label="{{ $clientFolder->display_name }}, {{ str($clientFolder->status->value)->replace('_', ' ')->title() }}, {{ number_format((float) $clientFolder->progress_percent) }} percent complete"
                                    data-folder-tile
                                    data-folder-preview="client-folder-preview-{{ $clientFolder->id }}"
                                    data-folder-open-url="{{ route('client-folders.show', $clientFolder) }}"
                                    data-folder-menu="client-folder-menu-{{ $clientFolder->id }}"
                                >
                                    <span class="client-folder-selection" aria-hidden="true"><x-ui.icon name="check" size="size-3.5" /></span>
                                    <span class="client-folder-glyph" aria-hidden="true"></span>
                                    <h3 class="client-folder-name" title="{{ $clientFolder->display_name }}" data-folder-name-for="{{ $clientFolder->id }}">{{ $clientFolder->display_name }}</h3>
                                    <div class="mt-2 w-full" aria-label="{{ $clientFolder->display_name }} progress">
                                        <div class="h-1.5 overflow-hidden rounded-full bg-ui-border" role="progressbar" aria-label="Folder completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (float) $clientFolder->progress_percent }}">
                                            <div class="h-full rounded-full bg-progress" style="width: {{ max(0, min(100, (float) $clientFolder->progress_percent)) }}%"></div>
                                        </div>
                                        <div class="mt-1.5 flex items-center justify-between gap-2 text-[0.68rem]">
                                            <x-ui.status-badge :status="$clientFolder->status" class="px-2 py-0.5 text-[0.65rem]" />
                                            <span class="font-bold tabular-nums text-text-muted">{{ number_format((float) $clientFolder->progress_percent) }}%</span>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="client-folder-menu-trigger ui-dots-trigger" aria-label="Actions for {{ $clientFolder->display_name }}" aria-haspopup="menu" aria-expanded="false" aria-controls="client-folder-menu-{{ $clientFolder->id }}" data-folder-menu-trigger>
                                    <x-ui.icon name="more" size="size-5" />
                                </button>
                                <div id="client-folder-menu-{{ $clientFolder->id }}" class="client-folder-menu" role="menu" aria-label="Actions for {{ $clientFolder->display_name }}" hidden data-folder-action-menu>
                                    <a href="{{ route('client-folders.show', $clientFolder) }}" role="menuitem" class="client-folder-menu-item" data-folder-open-action><x-ui.icon name="open" size="size-4" />Open</a>
                                    @can('update', $clientFolder)
                                        <button type="button" id="folder-rename-{{ $clientFolder->id }}" role="menuitem" class="client-folder-menu-item" data-modal-open="folder-rename-dialog-{{ $clientFolder->id }}"><x-ui.icon name="edit" size="size-4" />Rename</button>
                                    @endcan
                                    @can('delete', $clientFolder)
                                        <button type="button" id="dashboard-recycle-{{ $clientFolder->id }}" role="menuitem" class="client-folder-menu-item text-danger hover:bg-danger-soft" data-modal-open="dashboard-recycle-dialog-{{ $clientFolder->id }}"><x-ui.icon name="trash" size="size-4" />Move to Recycle Bin</button>
                                    @endcan
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if($clientFolders->hasPages())
                        {{-- data-folder-pagination is the hook app.js uses to intercept ordinary
                             left-clicks and AUTO-UPDATE just this fragment instead of navigating
                             the whole page. Without JS these stay plain links and still work. --}}
                        <nav class="mt-6" aria-label="Client folders pagination" data-folder-pagination>{{ $clientFolders->onEachSide(1)->links() }}</nav>
                    @endif
                @endif
            </div>

        <div class="client-folder-preview-backdrop" data-folder-preview-backdrop></div>
        <aside id="folder-preview-panel" class="client-folder-preview-panel" aria-label="Selected client folder details" aria-live="polite" data-folder-preview-panel data-dashboard-right-panel-container>
            <div class="flex items-center justify-between px-5 py-4 xl:hidden">
                <p class="font-bold">Folder details</p>
                <button type="button" class="ui-icon-button -mr-2" aria-label="Close folder details" data-folder-preview-close><x-ui.icon name="close" /></button>
            </div>
            <div class="client-folder-preview-content" data-folder-preview-content>
                <div class="grid min-h-80 place-items-center p-6 text-center">
                    <div>
                        <span class="client-folder-glyph mx-auto scale-110" aria-hidden="true"></span>
                        <h3 class="mt-5 text-lg font-bold">Select a Client Folder</h3>
                        <p class="mx-auto mt-2 max-w-xs text-sm leading-6 text-text-muted">Select a folder to view its details and folder contents.</p>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <div data-folder-browser-artifacts>
    @foreach($clientFolders as $clientFolder)
        @php
            $renameHasError = (string) old('rename_folder_id') === (string) $clientFolder->id;
            $folderModules = [
                ['label' => 'CI / BI Report', 'description' => 'Credit Investigation / Background Investigation', 'icon' => 'report', 'tone' => 'green', 'url' => route('client-folders.cibi-report.edit', $clientFolder)],
                ['label' => 'Business / Income Sources', 'description' => 'Business information and income-source evaluation', 'icon' => 'folder', 'tone' => 'violet', 'url' => route('client-folders.income-sources.index', $clientFolder)],
                ['label' => 'Residence & Business Report', 'description' => 'Residence and business verification', 'icon' => 'report', 'tone' => 'orange', 'url' => route('client-folders.residence-business.edit', $clientFolder)],
                ['label' => 'CI Activities', 'description' => 'Field investigation checklist and findings', 'icon' => 'activity', 'tone' => 'green', 'url' => route('client-folders.activities.index', $clientFolder)],
                ['label' => 'Generated Reports', 'description' => 'PDF/DOCX reports ready for download and printing', 'icon' => 'report', 'tone' => 'red', 'url' => route('client-folders.generated-reports.index', $clientFolder)],
                ['label' => 'Attachments / Documents', 'description' => 'Supporting documents', 'icon' => 'attachment', 'tone' => 'neutral', 'url' => route('client-folders.modules.show', [$clientFolder, 'attachments'])],
            ];
        @endphp
        <template id="client-folder-preview-{{ $clientFolder->id }}" data-folder-preview-template data-folder-id="{{ $clientFolder->id }}">
            <div>
                <header class="bg-brand-soft/55 p-3 sm:p-4">
                    <div class="flex items-start gap-2.5">
                        <span class="client-folder-glyph shrink-0" aria-hidden="true"></span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-base font-bold leading-snug" title="{{ $clientFolder->display_name }}" data-folder-name-for="{{ $clientFolder->id }}">{{ $clientFolder->display_name }}</h3>
                            <x-ui.status-badge :status="$clientFolder->status" class="mt-1" />
                        </div>
                    </div>

                    @php
                        $displayTimezone = config('cims.display_timezone');
                        $createdAt = $clientFolder->created_at->timezone($displayTimezone);
                        $updatedAt = $clientFolder->updated_at->timezone($displayTimezone);
                        $folderHistoryEvents = $folderHistoryByFolder->get($clientFolder->id, collect());
                    @endphp
                    <dl class="mt-2.5 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <dt class="font-semibold text-text-muted">Created</dt>
                            <dd class="mt-0.5 font-bold">{{ $createdAt->format('M j, Y') }} &middot; {{ $createdAt->format('g:i A') }}</dd>
                            <dd class="mt-0.5 truncate font-medium text-text-main">by {{ $clientFolder->creator?->full_name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-text-muted">Last updated</dt>
                            <dd class="mt-0.5 font-bold">{{ $updatedAt->format('M j, Y') }} &middot; {{ $updatedAt->format('g:i A') }}</dd>
                            <dd class="mt-0.5 truncate font-medium text-text-main">by {{ $clientFolder->updater?->full_name ?? '—' }}</dd>
                        </div>
                    </dl>

                    @if($folderHistoryEvents->isNotEmpty())
                        <button type="button" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-brand-primary hover:underline" data-modal-open="folder-history-dialog">
                            Folder History<span aria-hidden="true">&rarr;</span>
                        </button>
                    @endif

                    <div class="mt-2.5">
                        <a href="{{ route('client-folders.show', $clientFolder) }}" class="ui-button-primary min-h-10 w-full px-3.5 py-2 text-sm sm:w-auto" data-folder-open-action><x-ui.icon name="open" size="size-4" />Open Folder<span class="sr-only">: {{ $clientFolder->display_name }}</span></a>
                    </div>
                </header>

                @if($folderHistoryEvents->isNotEmpty())
                    <x-ui.client-folder-history-modal id="folder-history-dialog" :events="$folderHistoryEvents" />
                @endif

                <section aria-labelledby="folder-contents-title-{{ $clientFolder->id }}">
                    <h4 id="folder-contents-title-{{ $clientFolder->id }}" class="px-4 pb-1.5 pt-3 text-sm font-semibold">Folder Contents</h4>
                    <nav class="folder-contents-nav pb-2" aria-label="Folder contents for {{ $clientFolder->display_name }}">
                        @foreach($folderModules as $module)
                            <a href="{{ $module['url'] }}" class="folder-content-link" @if($module['label'] === 'CI / BI Report') data-modal-open="cibi-report-dialog" data-cibi-report-url="{{ $module['url'] }}" @elseif($module['label'] === 'Business / Income Sources') data-modal-open="business-report-dialog" data-business-report-url="{{ $module['url'] }}" @endif>
                                <span @class([
                                    'folder-content-icon',
                                    'bg-blue-50 text-blue-700' => $module['tone'] === 'blue',
                                    'bg-brand-soft text-brand-primary' => $module['tone'] === 'green',
                                    'bg-violet-50 text-violet-700' => $module['tone'] === 'violet',
                                    'bg-orange-50 text-orange-700' => $module['tone'] === 'orange',
                                    'bg-danger-soft text-danger' => $module['tone'] === 'red',
                                    'bg-surface-muted text-text-muted' => $module['tone'] === 'neutral',
                                ])><x-ui.icon :name="$module['icon']" size="size-4" /></span>
                                <span class="min-w-0 flex-1"><span class="block text-xs font-semibold text-text-main">{{ $module['label'] }}</span><span class="block text-[0.68rem] leading-4 text-text-muted">{{ $module['description'] }}</span></span>
                                <x-ui.icon name="chevron-right" size="size-4" class="text-text-subtle" />
                            </a>
                        @endforeach
                    </nav>
                </section>
            </div>
        </template>

        @can('update', $clientFolder)
            <x-ui.modal id="folder-rename-dialog-{{ $clientFolder->id }}" title="Rename Folder" size="max-w-md" :data-open-on-error="$renameHasError ? 'true' : 'false'">
                <form id="folder-rename-form-{{ $clientFolder->id }}" method="POST" action="{{ route('client-folders.update-name', $clientFolder) }}" data-folder-rename-form data-folder-id="{{ $clientFolder->id }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="rename_folder_id" value="{{ $clientFolder->id }}">
                    <p class="mb-4 text-sm leading-5 text-text-muted">Current folder: <strong class="font-semibold text-text-main" data-folder-name-for="{{ $clientFolder->id }}">{{ $clientFolder->display_name }}</strong></p>
                    <label for="folder-display-name-{{ $clientFolder->id }}" class="ui-label">Folder name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="folder-display-name-{{ $clientFolder->id }}" name="display_name" value="{{ (string) old('rename_folder_id') === (string) $clientFolder->id ? old('display_name') : $clientFolder->display_name }}" class="ui-control" required maxlength="255" autocomplete="off" autofocus @if($renameHasError) aria-invalid="true" aria-describedby="folder-display-name-error-{{ $clientFolder->id }}" @endif>
                    <p id="folder-display-name-error-{{ $clientFolder->id }}" class="mt-2 text-sm font-semibold text-danger" role="alert" @if(! ($renameHasError && $errors->has('display_name'))) hidden @endif>{{ $renameHasError ? $errors->first('display_name') : '' }}</p>
                </form>
                <x-slot:footer>
                    <button type="button" data-modal-close class="ui-button-secondary">Cancel</button>
                    <button type="submit" form="folder-rename-form-{{ $clientFolder->id }}" class="ui-button-primary">Rename</button>
                </x-slot:footer>
            </x-ui.modal>
        @endcan

        @can('delete', $clientFolder)
            <x-ui.modal id="dashboard-recycle-dialog-{{ $clientFolder->id }}" title="Move to Recycle Bin" size="max-w-md">
                <p class="text-sm leading-6 text-text-muted">Are you sure you want to move <strong class="font-semibold text-text-main" data-folder-name-for="{{ $clientFolder->id }}">&ldquo;{{ $clientFolder->display_name }}&rdquo;</strong> to the Recycle Bin?</p>
                <p class="mt-3 text-sm leading-6 text-text-muted">The folder can be restored according to the existing authorization rules.</p>
                <x-slot:footer>
                    <button type="button" data-modal-close class="ui-button-secondary">Cancel</button>
                    <form method="POST" action="{{ route('client-folders.destroy', $clientFolder) }}" data-folder-recycle-form data-folder-id="{{ $clientFolder->id }}" data-folder-status="{{ $clientFolder->status->value }}">
                        @csrf
                        @method('DELETE')
                        <button class="ui-button-danger">Move to Recycle Bin</button>
                    </form>
                </x-slot:footer>
            </x-ui.modal>
        @endcan
    @endforeach

    @include('client-folders._create-modal')
    <x-ui.cibi-report-modal />
    <x-ui.business-report-modal />
    </div>
</section>
