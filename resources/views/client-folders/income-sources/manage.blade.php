@extends('layouts.app')

@section('title', 'Business / Income Sources · '.$clientFolder->display_name)

@section('content')
    @php($personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null))

    <script>
        (function () {
            try {
                if (localStorage.getItem('brbi-business-recent-activity-collapsed') === 'true') {
                    document.documentElement.setAttribute('data-business-recent-activity-collapsed', '');
                }
            } catch (e) {}
        })();
    </script>

    <style>
        html[data-business-recent-activity-collapsed] [data-business-activities-layout] {
            gap: 0;
        }

        html[data-business-recent-activity-collapsed] [data-business-history-shell] {
            display: none;
        }

        [data-business-activities-layout] {
            transition: gap 200ms ease-in-out;
        }

        [data-business-history-shell] {
            display: grid;
            min-width: 0;
            grid-template-rows: minmax(0, 1fr);
            transition: grid-template-rows 200ms ease-in-out;
        }

        [data-business-history-panel] {
            min-height: 0;
            overflow: hidden;
            opacity: 1;
            transform: translateX(0) scale(1);
            transform-origin: right center;
            transition: opacity 160ms ease-out, transform 200ms ease-out;
        }

        [data-business-activities-layout][data-history-state="collapsed"] {
            gap: 0;
        }

        [data-business-activities-layout][data-history-state="collapsed"] [data-business-history-shell] {
            grid-template-rows: minmax(0, 0fr);
        }

        [data-business-activities-layout][data-history-state="collapsed"] [data-business-history-panel] {
            pointer-events: none;
            opacity: 0;
            transform: translateX(0.375rem) scale(0.99);
            transition-timing-function: ease-in;
        }

        @media (min-width: 1280px) {
            html[data-business-recent-activity-collapsed] [data-business-activities-layout] {
                grid-template-columns: minmax(0, 1fr);
            }

            [data-business-activities-layout] {
                grid-template-columns: minmax(0, 4fr) minmax(15rem, 1fr);
                transition: grid-template-columns 200ms ease-in-out, gap 200ms ease-in-out;
            }

            [data-business-activities-layout][data-history-state="collapsed"] {
                grid-template-columns: minmax(0, 1fr) minmax(0, 0fr);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            [data-business-activities-layout],
            [data-business-history-shell],
            [data-business-history-panel] {
                transition-duration: 1ms !important;
            }

            [data-business-history-panel] {
                transform: none !important;
            }
        }
    </style>

    <x-ui.breadcrumb :items="[
        ['label' => 'Client Folders', 'url' => route('client-folders.index')],
        ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', [$clientFolder] + $personParams)],
        ['label' => 'Business / Income Sources'],
    ]" />

    @error('income_source')<div class="mb-6 rounded-card border border-danger/30 bg-danger-soft p-4 text-sm font-semibold text-danger" role="alert">{{ $message }}</div>@enderror

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,4fr)_minmax(15rem,1fr)]" data-business-activities-layout data-history-state="expanded">
        <section class="ui-panel min-w-0 p-4 sm:p-5" aria-labelledby="saved-businesses-title" data-business-panel-body>
            @include('client-folders.income-sources.partials.saved-businesses-panel-body')
        </section>

        <div class="min-w-0" data-business-history-shell>
            <aside id="business-history-panel" class="ui-panel min-w-0 p-4 sm:p-5" aria-labelledby="business-recent-activity-title" data-business-history-panel>
                <div data-business-activity-body>
                    @include('client-folders.income-sources.partials.recent-activity-body')
                </div>
            </aside>
        </div>
    </div>

    <x-ui.recent-activity-modal id="business-recent-activity-dialog" :activities="$recentActivity" />

    <script>
        // Exposed on window (not a plain DOMContentLoaded-only IIFE) because the hide/show button
        // nodes queried here live inside the two AUTO-UPDATE-swappable regions (Saved Businesses
        // panel body + Recent Activity body, see app.js's refreshBusinessManagePage()) — a fresh
        // Add/Update/Delete re-render replaces those nodes, so their listeners must be rewired
        // after every such swap, not just once at page load. The persisted expand/collapse state
        // itself lives on `layout`/`shell`/the `<aside>` element, none of which are ever replaced,
        // so a re-init only needs to resync the new button nodes to that still-current state.
        window.initBusinessHistoryToggle = function initBusinessHistoryToggle() {
            const layout = document.querySelector('[data-business-activities-layout]');
            const shell = document.querySelector('[data-business-history-shell]');
            const panel = document.querySelector('[data-business-history-panel]');
            const hideButton = document.querySelector('[data-business-history-hide]');
            const showButton = document.querySelector('[data-business-history-show]');
            const desktopColumns = 'xl:grid-cols-[minmax(0,4fr)_minmax(15rem,1fr)]';
            const storageKey = 'brbi-business-recent-activity-collapsed';
            const alreadyInitialized = layout instanceof HTMLElement && layout.dataset.historyState !== '' && layout.dataset.businessHistoryReady === 'true';
            const initiallyCollapsed = ! alreadyInitialized && document.documentElement.hasAttribute('data-business-recent-activity-collapsed');
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            let collapseTimer = null;
            if (!(layout instanceof HTMLElement)
                || !(shell instanceof HTMLElement)
                || !(panel instanceof HTMLElement)
                || !(hideButton instanceof HTMLButtonElement)
                || !(showButton instanceof HTMLButtonElement)) return;

            const setExpandedState = (expanded) => {
                hideButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                showButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            };

            const syncFinalVisibility = (hidden) => {
                shell.hidden = hidden;
                panel.hidden = hidden;
            };

            const syncLayoutClasses = (hidden) => {
                layout.classList.toggle(desktopColumns, ! hidden);
                layout.classList.toggle('gap-5', ! hidden);
            };

            const persistCollapsedState = (collapsed) => {
                try {
                    localStorage.setItem(storageKey, String(collapsed));
                } catch (e) {}
            };

            const finishCollapse = () => {
                collapseTimer = null;
                if (layout.dataset.historyState === 'collapsed') syncFinalVisibility(true);
            };

            const hideHistoryPanel = () => {
                if (collapseTimer !== null) window.clearTimeout(collapseTimer);
                shell.hidden = false;
                showButton.hidden = false;
                layout.dataset.historyState = 'collapsed';
                syncLayoutClasses(true);
                setExpandedState(false);
                persistCollapsedState(true);

                if (reducedMotion) {
                    finishCollapse();
                    return;
                }

                collapseTimer = window.setTimeout(finishCollapse, 220);
            };

            const showHistoryPanel = () => {
                if (collapseTimer !== null) window.clearTimeout(collapseTimer);
                syncFinalVisibility(false);
                showButton.hidden = true;
                syncLayoutClasses(false);
                setExpandedState(true);
                persistCollapsedState(false);

                if (reducedMotion) {
                    layout.dataset.historyState = 'expanded';
                    return;
                }

                layout.dataset.historyState = 'collapsed';
                void shell.offsetHeight;
                window.requestAnimationFrame(() => {
                    layout.dataset.historyState = 'expanded';
                });
            };

            if (alreadyInitialized) {
                // Re-init after an AUTO-UPDATE DOM swap: keep whatever state is already current
                // (the user may have hidden/shown the panel before this refresh landed) and just
                // resync the freshly-rendered button nodes to it.
                const collapsedNow = layout.dataset.historyState === 'collapsed';
                syncFinalVisibility(collapsedNow);
                showButton.hidden = ! collapsedNow;
                setExpandedState(! collapsedNow);
            } else if (initiallyCollapsed) {
                layout.dataset.historyState = 'collapsed';
                syncLayoutClasses(true);
                syncFinalVisibility(true);
                showButton.hidden = false;
                setExpandedState(false);
            } else {
                layout.dataset.historyState = 'expanded';
                syncLayoutClasses(false);
                syncFinalVisibility(false);
                showButton.hidden = true;
                setExpandedState(true);
            }
            layout.dataset.businessHistoryReady = 'true';
            document.documentElement.removeAttribute('data-business-recent-activity-collapsed');

            hideButton.addEventListener('click', hideHistoryPanel);
            showButton.addEventListener('click', showHistoryPanel);
        };

        document.addEventListener('DOMContentLoaded', () => window.initBusinessHistoryToggle());
    </script>

    <x-ui.business-template-modal :business-templates="$businessTemplates" :used-template-ids="$usedTemplateIds ?? []" />

    <a id="business-report-trigger" hidden data-modal-open="business-report-dialog" data-business-report-url="{{ route('client-folders.income-sources.index', [$clientFolder] + $personParams) }}" data-business-report-base-url="{{ route('client-folders.income-sources.index', [$clientFolder] + $personParams) }}"></a>

    <x-ui.business-report-modal />
@endsection
