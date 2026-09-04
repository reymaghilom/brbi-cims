import './bootstrap';

const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function setDrawer(open) {
    const drawer = document.querySelector('[data-mobile-drawer]');
    const backdrop = document.querySelector('[data-mobile-backdrop]');
    const toggle = document.querySelector('[data-drawer-toggle]');

    if (!drawer || !backdrop || !toggle) return;

    drawer.classList.toggle('-translate-x-full', !open);
    backdrop.hidden = !open;
    toggle.setAttribute('aria-expanded', String(open));
    document.body.classList.toggle('overflow-hidden', open);

    if (open) drawer.querySelector(focusableSelector)?.focus();
    else toggle.focus();
}

// Desktop sidebar collapse/expand — same top menu button as the mobile drawer toggle, just a
// different behavior once the sidebar is permanently pinned (lg breakpoint). State is mirrored
// onto <html data-sidebar-collapsed> (an inline <script> in layouts/app.blade.php already set
// this from localStorage before first paint, so there's no expanded-then-collapsed flash) and
// persisted so it survives refresh and navigation.
const SIDEBAR_COLLAPSED_KEY = 'brbi-sidebar-collapsed';
const desktopSidebarMedia = window.matchMedia('(min-width: 1024px)');

function isSidebarCollapsed() {
    return document.documentElement.hasAttribute('data-sidebar-collapsed');
}

function syncSidebarToggleButton() {
    const toggle = document.querySelector('[data-drawer-toggle]');
    if (!toggle) return;

    if (desktopSidebarMedia.matches) {
        const collapsed = isSidebarCollapsed();
        toggle.setAttribute('aria-expanded', String(!collapsed));
        toggle.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Collapse navigation');
    } else {
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Open navigation');
    }
}

function setSidebarCollapsed(collapsed) {
    document.documentElement.toggleAttribute('data-sidebar-collapsed', collapsed);
    syncSidebarToggleButton();
    document.getElementById('sidebar-tooltip')?.removeAttribute('data-visible');
    try {
        localStorage.setItem(SIDEBAR_COLLAPSED_KEY, String(collapsed));
    } catch {
        // Private browsing / storage disabled — the toggle still works for the session, it just
        // won't be remembered next visit.
    }
}

syncSidebarToggleButton();
desktopSidebarMedia.addEventListener('change', syncSidebarToggleButton);

// Collapsed-sidebar link tooltips. The sidebar is its own scroll container
// (overflow-y-auto), and a non-"visible" overflow-y forces overflow-x to compute as "auto"
// too even when set to "visible" explicitly (see the CSS Overflow spec) — so a tooltip
// positioned relative to the link itself would get clipped at the sidebar's edge instead of
// floating past it. One shared #sidebar-tooltip element (a sibling of the sidebar, not a
// descendant) sidesteps that entirely; it's just repositioned to whichever link is currently
// hovered/focused.
function showSidebarTooltip(link) {
    if (!desktopSidebarMedia.matches || !isSidebarCollapsed()) return;
    const tooltip = document.getElementById('sidebar-tooltip');
    const label = link.querySelector('span')?.textContent?.trim();
    if (!tooltip || !label) return;

    const rect = link.getBoundingClientRect();
    tooltip.textContent = label;
    tooltip.style.left = `${rect.right + 10}px`;
    tooltip.style.top = `${rect.top + rect.height / 2}px`;
    tooltip.style.transform = 'translateY(-50%)';
    tooltip.toggleAttribute('data-visible', true);
}

function hideSidebarTooltip() {
    document.getElementById('sidebar-tooltip')?.removeAttribute('data-visible');
}

document.querySelectorAll('#primary-sidebar .ui-sidebar-link').forEach((link) => {
    link.addEventListener('mouseenter', () => showSidebarTooltip(link));
    link.addEventListener('mouseleave', hideSidebarTooltip);
    link.addEventListener('focus', () => showSidebarTooltip(link));
    link.addEventListener('blur', hideSidebarTooltip);
});
desktopSidebarMedia.addEventListener('change', hideSidebarTooltip);

function closeFolderMenus({ restoreFocus = false } = {}) {
    document.querySelectorAll('[data-folder-action-menu]:not([hidden])').forEach((menu) => {
        menu.hidden = true;
        menu.removeAttribute('data-menu-position');
        menu.style.removeProperty('left');
        menu.style.removeProperty('top');
        const trigger = document.querySelector(`[aria-controls="${menu.id}"]`);
        trigger?.setAttribute('aria-expanded', 'false');
        if (restoreFocus) trigger?.focus();
    });
}

function openFolderMenu(menu, trigger, coordinates = null) {
    closeFolderMenus();
    menu.hidden = false;
    trigger?.setAttribute('aria-expanded', 'true');

    if (coordinates) {
        menu.dataset.menuPosition = 'fixed';
        const rect = menu.getBoundingClientRect();
        const left = Math.min(coordinates.x, window.innerWidth - rect.width - 12);
        const top = Math.min(coordinates.y, window.innerHeight - rect.height - 12);
        menu.style.left = `${Math.max(12, left)}px`;
        menu.style.top = `${Math.max(12, top)}px`;
    }

    menu.querySelector('[role="menuitem"]')?.focus();
}

function selectClientFolder(tile, { openPreview = true } = {}) {
    const browser = tile.closest('[data-folder-browser]');
    if (!browser) return;

    browser.querySelectorAll('[data-folder-tile]').forEach((item) => {
        item.setAttribute('aria-selected', String(item === tile));
    });

    const template = document.getElementById(tile.dataset.folderPreview);
    const content = browser.querySelector('[data-folder-preview-content]');
    if (template instanceof HTMLTemplateElement && content) {
        content.replaceChildren(template.content.cloneNode(true));
    }

    if (openPreview && !window.matchMedia('(min-width: 1280px)').matches) {
        const panel = browser.querySelector('[data-folder-preview-panel]');
        const backdrop = browser.querySelector('[data-folder-preview-backdrop]');
        panel?.setAttribute('data-open', 'true');
        backdrop?.setAttribute('data-open', 'true');
        document.body.classList.add('overflow-hidden');
    }
}

function closeFolderPreview(browser) {
    // Both the panel's slide and the backdrop's fade are CSS transitions keyed off this same
    // data-open attribute (see .client-folder-preview-panel / .client-folder-preview-backdrop) —
    // removing it lets each finish its own animation instead of an instant display:none cut.
    browser?.querySelector('[data-folder-preview-panel]')?.removeAttribute('data-open');
    browser?.querySelector('[data-folder-preview-backdrop]')?.removeAttribute('data-open');
    document.body.classList.remove('overflow-hidden');
}

// Same data-toast/data-toast-close markup as the server-rendered <x-ui.toast> component (see
// resources/views/components/ui/toast.blade.php) so a client-triggered toast looks and behaves
// identically — including the existing delegated [data-toast-close] click handler above, which
// works on either one already.
function showToast(message, type = 'success', duration = 4500) {
    const region = document.querySelector('[data-toast-region]');
    if (!region) return;
    const toneClass = { error: 'border-danger/25 bg-danger-soft text-danger', info: 'border-progress/25 bg-progress-soft text-progress' }[type]
        ?? 'border-success/25 bg-success-soft text-success';
    const toast = document.createElement('div');
    toast.dataset.toast = '';
    toast.className = `flex items-start gap-3 rounded-card border p-4 text-sm font-semibold shadow-float ${toneClass}`;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    const text = document.createElement('p');
    text.className = 'min-w-0 flex-1';
    text.textContent = message;
    const close = document.createElement('button');
    close.type = 'button';
    close.dataset.toastClose = '';
    close.className = '-m-2 rounded p-2';
    close.setAttribute('aria-label', 'Dismiss message');
    close.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" class="size-4 shrink-0"><path d="m6 6 12 12M18 6 6 18"/></svg>';
    toast.append(text, close);
    region.append(toast);
    window.setTimeout(() => toast.remove(), duration);
}

const FOLDER_PREVIEW_COLLAPSED_STORAGE_KEY = 'brbi-folder-preview-collapsed';

// The collapsed
// state lives on [data-folder-browser-layout] itself (read by the CSS in _folder-browser.blade.php
// that actually animates grid-template-columns), and on two separate Show/Hide buttons toggled via
// the plain [hidden] attribute — never by rewriting one button's icon/label, so the browser's own
// pre-paint CSS and this function agree on how "collapsed" is represented with no extra JS-only
// state to keep in sync.
const folderPreviewCollapseTimers = new WeakMap();

/**
 * Marks the panel as fully out of the layout. Zeroing its grid column is NOT enough on its own:
 * the panel stays a grid item, and a grid row is as tall as its tallest item, so a panel squeezed
 * to a 0px column simply wraps its content into a very tall invisible box that keeps the whole
 * lower layout — and the document — that tall. This attribute is what the desktop CSS turns into
 * display:none, so the hidden panel stops contributing any height at all. It is deliberately a
 * dedicated attribute rather than the global [hidden]: below 1280px this same element is the
 * fixed slide-in drawer, and the rule that consumes this attribute is scoped to the desktop
 * media query so the drawer keeps working.
 */
function setFolderPreviewPanelInLayout(browser, inLayout) {
    const panel = browser?.querySelector('[data-folder-preview-panel]');
    if (!panel) return;
    if (inLayout) panel.removeAttribute('data-panel-hidden');
    else panel.setAttribute('data-panel-hidden', 'true');
}

// The collapsed
// state lives on [data-folder-browser-layout] itself (read by the CSS in _folder-browser.blade.php
// that actually animates grid-template-columns), and on two separate Show/Hide buttons toggled via
// the plain [hidden] attribute — never by rewriting one button's icon/label, so the browser's own
// pre-paint CSS and this function agree on how "collapsed" is represented with no extra JS-only
// state to keep in sync.
//
// `animate: false` is for restoring a persisted choice (first paint, or after the live-search
// fragment swap), where there is no transition to protect and the panel must be in its final
// layout state immediately.
function applyFolderPreviewCollapsedState(browser, collapsed, { animate = false } = {}) {
    const layout = browser?.querySelector('[data-folder-browser-layout]');
    const showButton = browser?.querySelector('[data-folder-preview-show]');
    const hideButton = browser?.querySelector('[data-folder-preview-hide]');
    if (!layout) return;

    const pendingCollapse = folderPreviewCollapseTimers.get(layout);
    if (pendingCollapse) {
        window.clearTimeout(pendingCollapse);
        folderPreviewCollapseTimers.delete(layout);
    }

    if (collapsed) {
        // Collapsing: keep the panel in the layout for the duration of the transition so it can
        // actually be seen fading/closing, then drop it out of the layout once that finishes.
        // Removing it up front would make the animation vanish instantly.
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        layout.setAttribute('data-panel-collapsed', 'true');
        if (animate && !reducedMotion) {
            folderPreviewCollapseTimers.set(layout, window.setTimeout(() => {
                folderPreviewCollapseTimers.delete(layout);
                if (layout.getAttribute('data-panel-collapsed') === 'true') setFolderPreviewPanelInLayout(browser, false);
            }, 220));
        } else {
            setFolderPreviewPanelInLayout(browser, false);
        }
    } else {
        // Expanding: put the panel back into the layout first, let the browser commit a collapsed
        // starting frame, and only then flip to the open state.
        setFolderPreviewPanelInLayout(browser, true);
        if (animate) {
            // Two frames commit the collapsed starting state without synchronously forcing layout
            // across every rendered folder card in the click handler.
            window.requestAnimationFrame(() => {
                window.requestAnimationFrame(() => layout.setAttribute('data-panel-collapsed', 'false'));
            });
        } else {
            layout.setAttribute('data-panel-collapsed', 'false');
        }
    }

    if (showButton) {
        if (collapsed) showButton.removeAttribute('hidden'); else showButton.setAttribute('hidden', '');
        showButton.setAttribute('aria-expanded', String(!collapsed));
    }
    if (hideButton) {
        if (collapsed) hideButton.setAttribute('hidden', ''); else hideButton.removeAttribute('hidden');
        hideButton.setAttribute('aria-expanded', String(!collapsed));
    }
}

function toggleFolderPreviewPanel(browser) {
    const layout = browser?.querySelector('[data-folder-browser-layout]');
    if (!layout) return;

    const collapsed = layout.getAttribute('data-panel-collapsed') === 'true';
    const nextCollapsed = !collapsed;
    applyFolderPreviewCollapsedState(browser, nextCollapsed, { animate: true });
    try {
        localStorage.setItem(FOLDER_PREVIEW_COLLAPSED_STORAGE_KEY, String(nextCollapsed));
    } catch (e) {
        // Persistence is best-effort — the toggle itself must still work without it.
    }
}

// Restores the persisted collapsed/expanded choice on first load (the pre-paint <script>/<style>
// pair in _folder-browser.blade.php already suppressed the wrong initial paint; this brings the
// live DOM state and the two Show/Hide buttons in sync with it) and clears the pre-paint flag now
// that app.js is in control.
function initFolderPreviewToggle(browser) {
    let collapsed = false;
    try {
        collapsed = localStorage.getItem(FOLDER_PREVIEW_COLLAPSED_STORAGE_KEY) === 'true';
    } catch (e) {
        // Fall back to expanded (the server-rendered default) when storage is unavailable.
    }
    applyFolderPreviewCollapsedState(browser, collapsed);
    document.documentElement.removeAttribute('data-folder-preview-collapsed');
}
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-folder-browser]').forEach(initFolderPreviewToggle);
});

function resetFolderPreview(browser) {
    const content = browser?.querySelector('[data-folder-preview-content]');
    if (content) {
        content.innerHTML = '<div class="grid min-h-80 place-items-center p-6 text-center"><div><span class="client-folder-glyph mx-auto" aria-hidden="true"></span><h3 class="mt-5 text-lg font-bold">Select a Client Folder</h3><p class="mx-auto mt-2 max-w-xs text-sm leading-6 text-text-muted">Select a folder to view its details and folder contents.</p></div></div>';
    }
    closeFolderPreview(browser);
}


function initializeClientSearch(search) {
    const input = search.querySelector('[data-client-search-input]');
    const clear = search.querySelector('[data-client-search-clear]');
    const form = search.closest('[data-client-search-form]');
    const liveEndpoint = search.dataset.liveSearchUrl;
    const browserContext = search.dataset.browserContext;
    if (!input || !clear || !form || !liveEndpoint || !browserContext) return;

    let liveDebounceTimer;
    let liveRequest;

    const updateClearVisibility = () => { clear.hidden = input.value.length === 0; };

    // `page` drives pagination through this exact same authoritative request path as live search:
    // same endpoint, same backend paginator, same abort/stale-response protection, same failure
    // handling. Omitting it (any search keystroke) deliberately falls back to page 1, so a changed
    // query can never land on a stale page number. `history` is 'replace' for search-as-you-type,
    // 'push' for a pagination click (so Back/Forward step through pages), and 'none' when we are
    // already responding to a popstate.
    const refreshFolderGrid = (delay = 275, { page = 1, history = 'replace' } = {}) => {
        clearTimeout(liveDebounceTimer);
        liveRequest?.abort();
        liveDebounceTimer = window.setTimeout(async () => {
            const browser = search.closest('[data-folder-browser]');
            if (!browser) return;
            const request = new AbortController();
            liveRequest = request;
            input.setAttribute('aria-busy', 'true');
            // Subtle in-flight state only — the grid dims slightly and pagination stops accepting
            // clicks. Never a blanking overlay or a spinner the rest of this Dashboard doesn't use.
            browser.setAttribute('data-refreshing', 'true');
            try {
                const url = new URL(liveEndpoint, window.location.origin);
                const query = input.value.trim();
                const currentParams = new URL(window.location.href).searchParams;
                if (query) url.searchParams.set('search', query);
                // Client Folders accepts these filters even though its current UI only exposes
                // Search. Keep valid URL-driven state intact through pagination/search/history;
                // the fragment endpoint remains authoritative for applying and validating it.
                ['status', 'sort'].forEach((name) => {
                    if (currentParams.has(name)) url.searchParams.set(name, currentParams.get(name));
                });
                url.searchParams.set('context', browserContext);
                if (page > 1) url.searchParams.set('page', String(page));
                const response = await fetch(url, {
                    headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: request.signal,
                });
                if (!response.ok) throw new Error('Folder search failed.');
                const holder = document.createElement('template');
                holder.innerHTML = await response.text();
                const nextLayout = holder.content.querySelector('[data-folder-browser-layout]');
                const nextArtifacts = holder.content.querySelector('[data-folder-browser-artifacts]');
                if (!nextLayout || !nextArtifacts) throw new Error('Folder search response was incomplete.');
                // The server always renders this fragment expanded (it has no knowledge of the
                // client's persisted choice) — carry the current collapsed/expanded state over to
                // the freshly rendered layout so live search never silently re-expands a Preview
                // Panel the user just hid. The toolbar itself (and its Show/Hide buttons) is never
                // touched by this replace — it lives outside [data-folder-browser-layout].
                const wasCollapsed = browser.querySelector('[data-folder-browser-layout]')?.getAttribute('data-panel-collapsed') === 'true';
                browser.querySelector('[data-folder-browser-layout]')?.replaceWith(nextLayout);
                // Re-apply through the shared helper rather than just stamping the attribute, so
                // the freshly rendered panel is also taken back out of the layout — otherwise a
                // live search while collapsed would reintroduce the tall invisible panel.
                if (wasCollapsed) applyFolderPreviewCollapsedState(browser, true);
                browser.querySelector('[data-folder-browser-artifacts]')?.replaceWith(nextArtifacts);
                document.body.classList.remove('overflow-hidden');

                const historyUrl = new URL(form.action, window.location.origin);
                if (query) historyUrl.searchParams.set('search', query);
                ['status', 'sort'].forEach((name) => {
                    if (currentParams.has(name)) historyUrl.searchParams.set(name, currentParams.get(name));
                });
                if (page > 1) historyUrl.searchParams.set('page', String(page));
                // pushState for a pagination click so Back/Forward walk the pages; replaceState
                // for search-as-you-type so every keystroke doesn't become a history entry; and
                // nothing at all when this refresh is itself the response to a popstate.
                if (history === 'push') window.history.pushState({ folderBrowserPage: page }, '', historyUrl);
                else if (history === 'replace') window.history.replaceState({ folderBrowserPage: page }, '', historyUrl);
            } catch (error) {
                // The existing folder list is deliberately left untouched on failure — the user
                // keeps the authoritative page they already had, plus the standard error toast.
                if (error.name !== 'AbortError') showToast('Client folders could not be refreshed. Please retry.', 'error');
            } finally {
                if (liveRequest === request) {
                    input.removeAttribute('aria-busy');
                    browser.removeAttribute('data-refreshing');
                }
            }
        }, delay);
    };

    input.addEventListener('input', () => {
        updateClearVisibility();
        refreshFolderGrid();
    });
    clear.addEventListener('click', () => {
        input.value = '';
        updateClearVisibility();
        resetFolderPreview(search.closest('[data-folder-browser]'));
        refreshFolderGrid(0);
        input.focus();
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        refreshFolderGrid(0);
    });
    // Pagination clicks (and the create/rename/delete AUTO-UPDATEs) all arrive through this one
    // event, so they share the same request, abort and error handling as live search.
    search.closest('[data-folder-browser]')?.addEventListener('folder-browser:refresh', (event) => {
        refreshFolderGrid(0, event.detail ?? {});
    });

    // Back/Forward: re-read the authoritative state from the URL the browser just restored (both
    // the page and the search term, so the input can't drift out of sync with what is rendered)
    // and AUTO-UPDATE to match — no hard reload, and no new history entry for a history move.
    window.addEventListener('popstate', () => {
        if (!search.closest('[data-folder-browser]')) return;
        const params = new URL(window.location.href).searchParams;
        input.value = params.get('search') ?? '';
        updateClearVisibility();
        refreshFolderGrid(0, { page: Number(params.get('page')) || 1, history: 'none' });
    });
}

// Dashboard/Client Folders pagination: an ordinary left-click AUTO-UPDATEs just the folder-browser
// fragment instead of navigating the whole page (which visibly blinked the header, sidebar, summary
// cards and toolbar for a change confined to the grid). Delegated, so pagination rendered by a
// previous AUTO-UPDATE keeps working with no rebinding.
//
// Every modified click is left completely alone — Ctrl/Cmd (open in new tab), Shift (new window),
// Alt (download), and any non-primary button — so the links behave like real links. Disabled
// arrows are rendered as <span aria-disabled="true">, not <a>, so they cannot match here and can
// never fire a request.
document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('[data-folder-pagination] a[href]');
    const browser = link?.closest('[data-folder-browser]');
    if (!link || !browser) return;

    const page = Number(new URL(link.href, window.location.origin).searchParams.get('page')) || 1;
    event.preventDefault();
    // A menu belonging to a card that is about to be replaced must not be left floating.
    closeFolderMenus();
    browser.dispatchEvent(new CustomEvent('folder-browser:refresh', { detail: { page, history: 'push' } }));
});

document.querySelectorAll('[data-client-search]').forEach(initializeClientSearch);

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-folder-create-form], [data-folder-rename-form], [data-folder-recycle-form]');
    if (!form) return;
    event.preventDefault();

    const submit = form.querySelector('button[type="submit"], button:not([type])')
        ?? (form.id ? document.querySelector(`button[type="submit"][form="${form.id}"]`) : null);
    if (form.matches('[data-folder-create-form]')) {
        form.querySelectorAll('[data-create-error-for]').forEach((error) => {
            error.textContent = '';
            error.hidden = true;
        });
        form.querySelectorAll('[aria-invalid="true"]').forEach((field) => field.removeAttribute('aria-invalid'));
    }
    submit?.setAttribute('disabled', 'disabled');
    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const payload = await response.json();
        if (response.status === 422 && form.matches('[data-folder-create-form]')) {
            let firstInvalid;
            Object.entries(payload.errors ?? {}).forEach(([fieldName, messages]) => {
                const field = form.elements.namedItem(fieldName);
                const error = form.querySelector(`[data-create-error-for="${fieldName}"]`);
                if (field instanceof HTMLElement) {
                    field.setAttribute('aria-invalid', 'true');
                    firstInvalid ??= field;
                }
                if (error) {
                    error.textContent = messages[0] ?? 'Enter a valid value.';
                    error.hidden = false;
                }
            });
            firstInvalid?.focus();
            return;
        }
        if (response.status === 422 && form.matches('[data-folder-rename-form]')) {
            const message = payload.errors?.display_name?.[0] ?? 'Enter a valid folder name.';
            const input = form.querySelector('[name="display_name"]');
            const error = form.querySelector('[role="alert"]');
            input?.setAttribute('aria-invalid', 'true');
            if (error) {
                error.textContent = message;
                error.hidden = false;
            }
            input?.focus();
            return;
        }
        if (!response.ok) throw new Error(payload.message || 'Folder action failed.');

        const dialog = form.closest('dialog');
        if (form.matches('[data-folder-create-form]')) {
            const browser = form.closest('[data-folder-browser]');
            dialog?.close();
            form.reset();
            ['total', 'on_progress'].forEach((key) => {
                const value = document.querySelector(`[data-dashboard-stat="${key}"] [data-summary-value]`);
                if (value) value.textContent = String(Number(value.textContent) + 1);
            });
            showToast(payload.message);
            browser?.dispatchEvent(new CustomEvent('folder-browser:refresh'));
        } else if (form.matches('[data-folder-rename-form]')) {
            dialog?.close();
            if (payload.no_change) {
                showToast(payload.message, 'info', 3500);
                return;
            }
            const folderId = form.dataset.folderId;
            const newName = payload.folder.display_name;
            document.querySelectorAll(`[data-folder-name-for="${folderId}"]`).forEach((element) => {
                element.textContent = newName;
                if (element.hasAttribute('title')) element.setAttribute('title', newName);
            });
            document.querySelectorAll(`template[data-folder-id="${folderId}"]`).forEach((template) => {
                template.content.querySelectorAll(`[data-folder-name-for="${folderId}"]`).forEach((element) => {
                    element.textContent = newName;
                    if (element.hasAttribute('title')) element.setAttribute('title', newName);
                });
            });
            const tile = document.querySelector(`[data-folder-shell][data-folder-id="${folderId}"] [data-folder-tile]`);
            if (tile) tile.setAttribute('aria-label', tile.getAttribute('aria-label').replace(/^.*?(?=, (?:On Progress|Completed),)/, newName));
            form.querySelector('[name="display_name"]').value = newName;
            showToast(payload.message);
            // AUTO-UPDATE Folder History: the name patches above are an instant visual echo, but
            // the authoritative "Renamed" entry the rename action just persisted to AuditLog only
            // exists in $folderHistoryByFolder on the server. Reuse the exact same
            // folder-browser:refresh re-fetch that Create/Delete already dispatch (see below) so
            // the reopened Folder History dialog for this folder shows it immediately — no second
            // history-only endpoint, no client-fabricated entry.
            form.closest('[data-folder-browser]')?.dispatchEvent(new CustomEvent('folder-browser:refresh'));
        } else {
            const folderId = form.dataset.folderId;
            const browser = form.closest('[data-folder-browser]');
            const shell = browser?.querySelector(`[data-folder-shell][data-folder-id="${folderId}"]`);
            const wasSelected = shell?.querySelector('[data-folder-tile]')?.getAttribute('aria-selected') === 'true';
            const status = form.dataset.folderStatus;
            dialog?.close();
            shell?.remove();
            if (wasSelected) resetFolderPreview(browser);
            ['total', status].forEach((key) => {
                const value = document.querySelector(`[data-dashboard-stat="${key}"] [data-summary-value]`);
                if (value) value.textContent = String(Math.max(0, Number(value.textContent) - 1));
            });
            showToast(payload.message);
            browser?.dispatchEvent(new CustomEvent('folder-browser:refresh'));
        }
    } catch (error) {
        showToast(error.message || 'Folder action failed. Please retry.', 'error');
    } finally {
        submit?.removeAttribute('disabled');
    }
});

const contextMenuClosing = new WeakMap();
const closeContextMenu = (menu, { restoreFocus = false } = {}) => {
    if (!(menu instanceof HTMLDetailsElement) || !menu.open) return;
    const panel = menu.querySelector('[data-context-menu-panel]');
    const summary = menu.querySelector(':scope > summary');
    if (!panel || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        menu.removeAttribute('open');
        if (restoreFocus) summary?.focus();
        return;
    }

    panel.dataset.state = 'closing';
    const finish = () => {
        if (contextMenuClosing.get(menu) !== finish) return;
        contextMenuClosing.delete(menu);
        menu.removeAttribute('open');
        if (restoreFocus) summary?.focus();
    };
    contextMenuClosing.set(menu, finish);
    window.requestAnimationFrame(() => {
        const animations = panel.getAnimations();
        if (animations.length === 0) {
            finish();
            return;
        }
        Promise.allSettled(animations.map((animation) => animation.finished)).then(finish);
    });
};

// [data-context-menu]'s panel is CSS `position: absolute` by default, which any ancestor with
// non-visible overflow (e.g. the Saved Businesses table's `overflow-x-auto` wrapper) clips or
// folds back into scrollable content instead of letting it float freely. On open, switch it to
// `position: fixed` computed from the trigger's own screen position — immune to ancestor overflow
// — flipping above the trigger when there isn't room below, and clamped horizontally to the
// viewport. This mirrors the existing co-maker action menu's positioning approach further below,
// generalized for every context menu instance instead of one bespoke implementation per feature.
document.addEventListener('toggle', (event) => {
    const details = event.target;
    if (!(details instanceof HTMLDetailsElement) || !details.matches('[data-context-menu]')) return;
    const panel = details.querySelector('[data-context-menu-panel]');
    const summary = details.querySelector('summary');
    if (!panel || !summary) return;

    if (!details.open) {
        contextMenuClosing.delete(details);
        delete panel.dataset.state;
        panel.style.removeProperty('position');
        panel.style.removeProperty('top');
        panel.style.removeProperty('left');
        panel.style.removeProperty('right');
        panel.style.removeProperty('margin-top');
        return;
    }

    const margin = 8;
    const rect = summary.getBoundingClientRect();
    const panelWidth = panel.offsetWidth;
    const panelHeight = panel.offsetHeight;

    let left = rect.right - panelWidth;
    if (left < margin) left = rect.left;
    left = Math.min(Math.max(left, margin), window.innerWidth - panelWidth - margin);

    let top = rect.bottom + 6;
    if (top + panelHeight > window.innerHeight - margin) {
        top = rect.top - panelHeight - 6;
    }
    top = Math.max(top, margin);

    // `position: fixed` is normally relative to the true viewport — but the header account
    // menu's panel sits inside <header class="... backdrop-blur">, and a backdrop-filter
    // ancestor becomes the containing block for fixed-position descendants, same as
    // `filter`/`transform` would. Left uncorrected, the left/top values above (computed against
    // the true viewport) land relative to the header's own box instead, offsetting the whole
    // panel — including the Logout button — by however far the header sits from the left edge,
    // which is exactly the sidebar's current width. That pushes it off-screen when the sidebar
    // is expanded (256px) but not when collapsed (76px), which is why Logout only appeared to
    // work in one state. Probing where a (0, 0) fixed position actually lands reveals whichever
    // containing block is really in effect — zero offset when it's the viewport (every other
    // context menu in the app), non-zero when an ancestor like the header intercepts it — so the
    // same code positions correctly either way.
    panel.style.position = 'fixed';
    panel.style.left = '0px';
    panel.style.top = '0px';
    panel.style.right = 'auto';
    const origin = panel.getBoundingClientRect();

    panel.style.left = `${left - origin.left}px`;
    panel.style.top = `${top - origin.top}px`;
    panel.style.marginTop = '0';
    panel.dataset.state = 'opening';
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        panel.dataset.state = 'open';
        return;
    }
    window.requestAnimationFrame(() => {
        if (details.open && panel.dataset.state === 'opening') panel.dataset.state = 'open';
    });
}, true);

window.addEventListener('scroll', () => {
    document.querySelectorAll('details[data-context-menu][open]').forEach((menu) => closeContextMenu(menu));
}, true);
window.addEventListener('resize', () => {
    document.querySelectorAll('details[data-context-menu][open]').forEach((menu) => closeContextMenu(menu));
});

document.addEventListener('click', (event) => {
    const contextMenuSummary = event.target.closest('details[data-context-menu] > summary');
    if (contextMenuSummary?.parentElement?.open) {
        event.preventDefault();
        closeContextMenu(contextMenuSummary.parentElement);
        return;
    }

    const activeContextMenu = event.target.closest('[data-context-menu]');
    document.querySelectorAll('details[data-context-menu][open]').forEach((menu) => {
        if (menu !== activeContextMenu) closeContextMenu(menu);
    });

    const contextMenuItem = event.target.closest('[data-context-menu] [role="menuitem"]');
    if (contextMenuItem) closeContextMenu(contextMenuItem.closest('details[data-context-menu]'));

    const menuTrigger = event.target.closest('[data-folder-menu-trigger]');
    if (menuTrigger) {
        const tile = menuTrigger.closest('[data-folder-shell]')?.querySelector('[data-folder-tile]');
        const menu = document.getElementById(menuTrigger.getAttribute('aria-controls'));
        const wasOpen = menu && !menu.hidden;
        if (tile) selectClientFolder(tile, { openPreview: false });
        closeFolderMenus();
        if (menu && !wasOpen) openFolderMenu(menu, menuTrigger);
        event.preventDefault();
        event.stopPropagation();
        return;
    }

    const previewClose = event.target.closest('[data-folder-preview-close], [data-folder-preview-backdrop]');
    if (previewClose) {
        closeFolderPreview(previewClose.closest('[data-folder-browser]'));
        return;
    }

    const previewToggle = event.target.closest('[data-folder-preview-show], [data-folder-preview-hide]');
    if (previewToggle) {
        toggleFolderPreviewPanel(previewToggle.closest('[data-folder-browser]'));
        return;
    }

    const folderTile = event.target.closest('[data-folder-tile]');
    if (folderTile && !event.target.closest('a, button, [role="menu"]')) {
        selectClientFolder(folderTile);
    } else if (!event.target.closest('[data-folder-action-menu]')) {
        closeFolderMenus();
    }

    if (event.target.closest('[data-drawer-toggle]')) {
        if (desktopSidebarMedia.matches) setSidebarCollapsed(!isSidebarCollapsed());
        else setDrawer(true);
    }
    if (event.target.closest('[data-drawer-close], [data-mobile-backdrop]')) setDrawer(false);

    const modalTrigger = event.target.closest('[data-modal-open]');
    if (modalTrigger) {
        event.preventDefault();
        closeFolderMenus();
        const dialog = document.getElementById(modalTrigger.dataset.modalOpen);
        if (dialog instanceof HTMLDialogElement) {
            const cibiFrame = dialog.querySelector('[data-cibi-report-frame]') || dialog.querySelector('[data-business-report-frame]') || dialog.querySelector('[data-check-report-frame]');
            const cibiLoading = dialog.querySelector('[data-cibi-report-loading]') || dialog.querySelector('[data-business-report-loading]') || dialog.querySelector('[data-check-report-loading]');
            const cibiUrl = modalTrigger.dataset.cibiReportUrl || modalTrigger.dataset.businessReportUrl || modalTrigger.dataset.checkReportUrl;
            if (cibiFrame instanceof HTMLIFrameElement && cibiUrl) {
                const requestedUrl = new URL(cibiUrl, window.location.href).href;
                const alwaysReload = dialog.matches('[data-business-report-dialog]') || dialog.matches('[data-check-report-dialog]');
                const sameUrl = cibiFrame.src === requestedUrl;
                if (alwaysReload || !sameUrl) {
                    if (cibiLoading) cibiLoading.hidden = false;
                    cibiFrame.addEventListener('load', () => {
                        if (cibiLoading) cibiLoading.hidden = true;
                    }, { once: true });
                    if (alwaysReload && sameUrl && cibiFrame.contentWindow) {
                        cibiFrame.contentWindow.location.replace(requestedUrl);
                    } else {
                        cibiFrame.src = requestedUrl;
                    }
                }
            }
            // The shared check-report-dialog is reused for both Residence and Business Checks —
            // its title must reflect whichever one is actually open right now, and reset back to
            // the generic default (never left showing "Residence Check" for a Business Check
            // opened afterward, or vice versa) whenever a trigger doesn't specify one.
            const titleHeading = dialog.querySelector('[data-check-report-title-heading]');
            if (titleHeading) {
                titleHeading.textContent = modalTrigger.dataset.checkReportTitle || titleHeading.dataset.checkReportDefaultTitle || titleHeading.textContent;
            }
            dialog.dataset.returnFocus = modalTrigger.id || '';
            dialog.showModal();
            dialog.querySelector('[data-modal-close], [autofocus]')?.focus();
        }
        // A modal-open trigger is a single, self-contained action — never let the same click also
        // be evaluated against unrelated concerns (Co-Maker triggers, reassign-confirm flow, etc.)
        // registered further down in this same delegated handler.
        return;
    }

    const addBusinessNext = event.target.closest('[data-add-business-next]');
    if (addBusinessNext) {
        const addBusinessDialog = addBusinessNext.closest('dialog');
        const templateSelect = addBusinessDialog?.querySelector('[data-add-business-template-select]');
        const templateError = addBusinessDialog?.querySelector('[data-add-business-template-error]');
        const reportTrigger = document.getElementById('business-report-trigger');
        if (templateSelect instanceof HTMLSelectElement) {
            if (!templateSelect.value) {
                templateSelect.setAttribute('aria-invalid', 'true');
                if (templateError) templateError.hidden = false;
                templateSelect.focus();
            } else if (reportTrigger) {
                templateSelect.removeAttribute('aria-invalid');
                if (templateError) templateError.hidden = true;
                // The base URL may already carry an active-person query string (?person=co-maker&co_maker_id=…),
                // so the template id has to be appended with the right separator rather than always "?".
                const baseUrl = reportTrigger.dataset.businessReportBaseUrl;
                const separator = baseUrl.includes('?') ? '&' : '?';
                reportTrigger.dataset.businessReportUrl = `${baseUrl}${separator}income_source_template_id=${encodeURIComponent(templateSelect.value)}`;
                addBusinessDialog.close();
                reportTrigger.click();
            }
        }
    }

    const modalClose = event.target.closest('[data-modal-close]');
    if (modalClose) modalClose.closest('dialog')?.close();

    // CI/BI Reassign Signatory: two-step flow — the data-entry dialog's "Reassign Signatory"
    // button never submits directly. It validates the form, copies the current/new signatory
    // names into the second (confirm) dialog, then swaps dialogs. Only the confirm dialog's own
    // "Yes, Reassign" button actually submits the form the first dialog built.
    const cibiReassignContinue = event.target.closest('[data-cibi-reassign-continue]');
    if (cibiReassignContinue) {
        const form = document.getElementById(cibiReassignContinue.dataset.cibiReassignContinue);
        if (form instanceof HTMLFormElement && form.reportValidity()) {
            const select = form.querySelector('[data-cibi-reassign-select]');
            const currentText = form.querySelector('[data-cibi-reassign-current]')?.textContent.trim() ?? '';
            const newText = select instanceof HTMLSelectElement ? (select.options[select.selectedIndex]?.text ?? '') : '';
            const confirmDialog = document.getElementById(form.id.replace(/-form$/, '-confirm'));
            if (confirmDialog instanceof HTMLDialogElement) {
                const fromEl = confirmDialog.querySelector('[data-cibi-reassign-confirm-from]');
                const toEl = confirmDialog.querySelector('[data-cibi-reassign-confirm-to]');
                if (fromEl) fromEl.textContent = currentText;
                if (toEl) toEl.textContent = newText;
                confirmDialog.dataset.cibiReassignFormId = form.id;
                cibiReassignContinue.closest('dialog')?.close();
                confirmDialog.showModal();
            }
        }
    }

    const cibiReassignConfirmSubmit = event.target.closest('[data-cibi-reassign-confirm-submit]');
    if (cibiReassignConfirmSubmit) {
        const confirmDialog = cibiReassignConfirmSubmit.closest('dialog');
        const form = confirmDialog?.dataset.cibiReassignFormId ? document.getElementById(confirmDialog.dataset.cibiReassignFormId) : null;
        if (form instanceof HTMLFormElement) form.requestSubmit();
    }

    // Cancel button rendered inside a Residence/Business Check page loaded in an iframe — the
    // page itself has no dialog to close (it's not the top-level document), so it reaches through
    // to the parent window's currently-open dialog instead.
    const closeParentDialog = event.target.closest('[data-close-parent-dialog]');
    if (closeParentDialog && window.parent !== window) {
        window.parent.document.querySelector('dialog[open]')?.close();
    }

    const toastClose = event.target.closest('[data-toast-close]');
    if (toastClose) toastClose.closest('[data-toast]')?.remove();
});

document.addEventListener('click', (event) => {
    const downloadButton = event.target.closest('[data-business-download-submit]');
    if (!downloadButton) return;
    if (downloadButton.dataset.downloading === 'true') {
        event.preventDefault();
        return;
    }
    downloadButton.dataset.downloading = 'true';
    const label = downloadButton.querySelector('[data-download-label]');
    const originalLabel = label?.textContent ?? '';
    if (label) label.textContent = 'Preparing…';
    window.setTimeout(() => { downloadButton.disabled = true; }, 0);
    window.setTimeout(() => {
        downloadButton.disabled = false;
        downloadButton.dataset.downloading = 'false';
        if (label) label.textContent = originalLabel;
    }, 2500);
});

// Auto-dismiss for every server-rendered toast (the session('status')/statusType flash — e.g. the
// Residence & Business Report page's own confirmation after a Residence Check save/update) — the
// manual [data-toast-close] button above still works regardless. ~4s applies uniformly rather than
// only to success messages: there's no existing per-type/per-page toast timer to hook into without
// building a whole second toast system, and 4s is strictly longer than the previous 3s, so no
// error/no-change/Cloudinary-failure message loses visible time versus before.
document.querySelectorAll('[data-toast]').forEach((toast) => {
    window.setTimeout(() => toast.remove(), 4000);
});

document.addEventListener('dblclick', (event) => {
    const tile = event.target.closest('[data-folder-tile]');
    if (!tile || event.target.closest('a, button, [role="menu"]')) return;
    window.location.assign(tile.dataset.folderOpenUrl);
});

// AUTO-UPDATE for a CI/BI Report save: the encoding form (inside the iframe) already saves via
// AJAX and posts this message the moment its own authoritative JSON response comes back — see the
// [data-cibi-form] submit handler below, which is the only sender. That means reaching here already
// proves the backend persisted the report, so the folder status pill / progress bar can be applied
// live, the canonical toast (same helper/duration as everywhere else) can be shown on the PARENT
// page — where it will actually still be visible once the dialog is gone — and the dialog can
// auto-close immediately after, with no reload and no follow-up GET of any kind.
window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin || event.data?.type !== 'brbi:cibi-saved') return;

    const returnUrl = new URL(event.data.returnUrl, window.location.href);
    if (returnUrl.origin !== window.location.origin) return;

    const dialog = document.querySelector('[data-cibi-report-dialog][open]');
    if (!dialog) return;

    // Only a save made for a different person/folder context (returnUrl doesn't match the page
    // currently behind this dialog) still needs a navigation — read by this dialog's own 'close'
    // handler further down, same pattern as the Business Report dialog above.
    dialog.dataset.cibiSavedReturnUrl = returnUrl.href;

    const currentUrl = new URL(window.location.href);
    if (currentUrl.pathname === returnUrl.pathname && currentUrl.search === returnUrl.search) {
        const folder = event.data.folder || {};

        // The CI/BI module card (status pill, description, Open/Add label, Preview/Download/
        // Reassign footer) is swapped whole from the same authoritative response — it already
        // encodes every state-derived decision (report presence, completion, export/reassign
        // eligibility) server-side, so there is nothing left to infer or hardcode here.
        if (typeof event.data.cibiModuleHtml === 'string') {
            const cibiModuleCard = document.getElementById('open-cibi-report');
            if (cibiModuleCard) cibiModuleCard.outerHTML = event.data.cibiModuleHtml;
        }

        const percentage = Number(folder.progress_percentage);
        if (Number.isFinite(percentage)) {
            const formatted = `${Math.round(percentage)}%`;
            document.querySelector('[data-folder-progress-label]')?.replaceChildren(formatted);
            document.querySelector('[data-folder-progress-bar] .tabular-nums')?.replaceChildren(formatted);
            const progressbar = document.querySelector('[data-folder-progress-bar] [role="progressbar"]');
            progressbar?.setAttribute('aria-valuenow', String(percentage));
            if (progressbar?.firstElementChild instanceof HTMLElement) progressbar.firstElementChild.style.width = `${percentage}%`;
        }

        // Recent Activity AUTO-UPDATEs from this exact same authoritative response — the
        // server-rendered fragment already reflects the just-saved CI/BI entry (same
        // AuditLog-backed source, same Applicant/exact-Co-Maker scoping as the initial page
        // render), so this is a straight swap, never a second GET and never a fabricated entry.
        if (typeof event.data.recentActivityHtml === 'string') {
            const recentActivityBody = document.querySelector('[data-recent-activity-body]');
            if (recentActivityBody) recentActivityBody.innerHTML = event.data.recentActivityHtml;
        }
    }

    if (event.data.message) showToast(event.data.message, event.data.statusType || 'success');

    dialog.close();
});

// AUTO-UPDATE for the Business / Income Sources page: applies an authoritative refresh payload
// (the exact { panel, activity, modal } JSON shape IncomeSourceController's refreshPayload()
// renders — Report Pending rows now live inside `panel` itself, alongside Saved rows, as one
// unified table) to the live DOM — no full page reload, no second network request to go fetch it.
// Every mutation path hands this function the payload it already has on hand from its own
// response: the Business Report modal's save notification below reads it straight off the
// 'brbi:business-saved' message (flashed onto the session by the save action itself and embedded
// in the iframe's own next page load — see business-encoding.blade.php), and the single/bulk
// delete AJAX submits further down read it from their own fetch response. Re-runs every
// page-scoped initializer whose DOM nodes just got replaced (search, sortable table, batch
// selection incl. Delete Selected, and the Recent Activity hide/show toggle) so none of them are
// left bound to now-detached elements.
function applyBusinessManageRefresh(payload) {
    const panelBody = document.querySelector('[data-business-panel-body]');
    const activityBody = document.querySelector('[data-business-activity-body]');
    const modal = document.getElementById('business-recent-activity-dialog');
    if (!panelBody || !activityBody) return;

    const searchTerm = document.querySelector('[data-business-search]')?.value ?? '';

    if (typeof payload.panel === 'string') panelBody.innerHTML = payload.panel;
    if (typeof payload.activity === 'string') activityBody.innerHTML = payload.activity;
    if (modal && typeof payload.modal === 'string') modal.outerHTML = payload.modal;

    const searchInput = document.querySelector('[data-business-search]');
    if (searchInput && searchTerm) searchInput.value = searchTerm;

    document.querySelectorAll('[data-business-sort-table]').forEach(initSortableTable);
    window.initBusinessSearch?.();
    window.initBusinessBatchPanel?.();
    window.initBusinessHistoryToggle?.();
}

// Single delete (per-row confirmation dialog, data-business-delete-form) and Delete Selected
// (data-business-delete-selected-form) both submit here via fetch instead of a native form POST —
// the response is the same authoritative refresh payload the modal save path uses (plus a
// `deleted` count), so a successful delete AUTO-UPDATEs the table/activity in place with no
// redirect, no reload, and no separate confirmation of "did it work" beyond the DOM actually
// changing. A failed delete never touches the DOM: the dialog stays open, the row(s) stay put, and
// the error is surfaced inline (bulk dialog) or as a toast (single-row dialog, which has no inline
// error slot of its own).
document.addEventListener('submit', (event) => {
    const form = event.target;
    // The single-row confirmation dialog carries data-business-delete-form on its <x-ui.modal>
    // root (that's where {{ $attributes }} lands in x-ui.confirmation-dialog), not on its own
    // <form> — so that one is matched via the closest dialog ancestor. The bulk dialog's form
    // carries its own marker attribute directly, since it isn't built from that shared component.
    if (!(form instanceof HTMLFormElement) || !(form.matches('[data-business-delete-selected-form]') || form.closest('[data-business-delete-form]'))) return;
    event.preventDefault();

    const dialog = form.closest('dialog');
    const isBulk = form.matches('[data-business-delete-selected-form]');
    const submitButton = form.querySelector('button[type="submit"]');
    const errorBox = dialog?.querySelector('[data-business-delete-selected-error]');
    const originalLabel = submitButton?.textContent ?? '';
    if (submitButton) { submitButton.disabled = true; submitButton.textContent = 'Deleting…'; }
    if (errorBox) { errorBox.hidden = true; errorBox.textContent = ''; }

    fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    })
        .then(async (response) => {
            if (!response.ok) {
                const body = await response.json().catch(() => null);
                throw new Error(body?.message || 'The selected Business Report(s) could not be deleted. Please try again.');
            }

            return response.json();
        })
        .then((payload) => {
            applyBusinessManageRefresh(payload);
            dialog?.close();
            const count = payload.deleted ?? 1;
            showToast(count === 1 ? '1 Business Report permanently deleted.' : `${count} Business Reports permanently deleted.`, 'success');
        })
        .catch((error) => {
            if (isBulk && errorBox) {
                errorBox.textContent = error.message;
                errorBox.hidden = false;
            } else {
                showToast(error.message, 'error');
            }
        })
        .finally(() => {
            if (submitButton) { submitButton.disabled = false; submitButton.textContent = originalLabel; }
        });
});

window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin || event.data?.type !== 'brbi:business-saved') return;

    const returnUrl = new URL(event.data.returnUrl, window.location.href);
    if (returnUrl.origin !== window.location.origin) return;

    const dialog = document.querySelector('[data-business-report-dialog][open]');
    if (!dialog) return;
    dialog.dataset.businessSavedReturnUrl = returnUrl.href;

    // AUTO-UPDATE the moment the save actually succeeds — the Businesses / Income Sources table
    // and Recent Activity apply the authoritative payload the message already carries (see
    // business-encoding.blade.php's data-business-saved-payload, sourced from the save action's
    // own session flash) with no follow-up request of any kind. Only applies if this parent page
    // is the exact one that save belongs to (same path + query string as returnUrl); a save made
    // while viewing a different person/folder context has nothing on this page to update.
    const currentUrl = new URL(window.location.href);
    if (currentUrl.pathname === returnUrl.pathname && currentUrl.search === returnUrl.search && event.data.payload) {
        applyBusinessManageRefresh(event.data.payload);
    }

    // The canonical success toast (same backend wording already used everywhere else — "Business
    // Report saved successfully.", etc. — see IncomeSourceController's afterSave()/store()) lives
    // on the PARENT page's own toast region, not inside the iframe: that page's own toast would be
    // destroyed the instant the dialog closes below, before anyone could ever see it. Shown here,
    // right before the auto-close, so it's already visible in the persistent parent DOM by the time
    // the dialog disappears, and uses the app's one existing toast helper/duration — no second
    // toast system.
    if (event.data.message) showToast(event.data.message, event.data.statusType || 'success');

    // AUTO-CLOSE only now, after the table/activity update above has already run synchronously —
    // this message only ever fires from the save action's own session-flash-backed notify element
    // (see business-encoding.blade.php), never from an iframe load, a form submit, or the Save
    // button being clicked alone, so reaching this point already proves the save persisted. The
    // dialog's own 'close' handler below reads businessSavedReturnUrl set above to either stay put
    // (same page, already current) or navigate to a different person/folder context — unchanged.
    dialog.close();
});

const businessSavedNotify = document.querySelector('[data-business-saved-notify]');
if (businessSavedNotify && window.parent !== window) {
    let businessSavedPayload = null;
    try {
        businessSavedPayload = JSON.parse(businessSavedNotify.dataset.businessSavedPayload || 'null');
    } catch (e) {
        // Malformed/missing payload just means no live AUTO-UPDATE happens on the parent side —
        // the notify element itself only ever renders after a real save, so this is not expected.
    }
    window.parent.postMessage({
        type: 'brbi:business-saved',
        returnUrl: businessSavedNotify.dataset.businessSavedReturnUrl,
        payload: businessSavedPayload,
        message: businessSavedNotify.dataset.businessSavedMessage,
        statusType: businessSavedNotify.dataset.businessSavedStatusType,
    }, window.location.origin);
}

window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin || event.data?.type !== 'brbi:check-saved') return;

    const returnUrl = new URL(event.data.returnUrl, window.location.href);
    if (returnUrl.origin !== window.location.origin) return;

    const dialog = document.querySelector('[data-check-report-dialog][open]');
    if (!dialog) return;
    dialog.dataset.checkSavedReturnUrl = returnUrl.href;
    // Unlike CI/BI Report and Business Report (which stay open for continued multi-section
    // editing), a Residence/Business Check is a single-shot save — closing immediately and
    // refreshing the list behind it is the whole point, so there's no reason to wait for a manual
    // close first. A toast shown right here, a moment before this same reload navigates away,
    // would just be thrown away with the rest of the DOM — so whenever the sender includes one
    // (both Business and Residence Check AJAX saves, straight from their XHR JSON responses),
    // it's stashed in
    // sessionStorage and picked up by the page-load check further down, after the reload actually
    // lands. Residence Check deliberately does NOT rely on session()->flash() surviving until that
    // reload instead — an unrelated request landing in between (e.g. the editing-presence
    // heartbeat's own 30s interval) can age it out first, which is exactly what made this toast
    // disappear intermittently before the message started traveling through this same relay.
    if (event.data.message) {
        try {
            sessionStorage.setItem('brbi:pending-toast', JSON.stringify({ message: event.data.message, type: event.data.statusType || 'success' }));
        } catch {
            // Storage can be unavailable (private browsing, quota) — losing the toast is fine, the
            // save itself already succeeded.
        }
    }
    dialog.close();
});

const checkSavedNotify = document.querySelector('[data-check-saved-notify]');
if (checkSavedNotify && window.parent !== window) {
    window.parent.postMessage({
        type: 'brbi:check-saved',
        returnUrl: checkSavedNotify.dataset.checkSavedReturnUrl,
        message: checkSavedNotify.dataset.checkSavedMessage,
        statusType: checkSavedNotify.dataset.checkSavedStatusType,
    }, window.location.origin);
}

// Companion to the sessionStorage stash above — runs on every page load (top-level only; the
// dialog's own iframe reload is never where this should surface) so the success toast survives
// the parent's post-save reload instead of depending on a server-side session flash still being
// present for that exact next request.
if (window.parent === window) {
    try {
        const pending = sessionStorage.getItem('brbi:pending-toast');
        if (pending) {
            sessionStorage.removeItem('brbi:pending-toast');
            const { message, type } = JSON.parse(pending);
            if (message) showToast(message, type || 'success');
        }
    } catch {
        // Malformed or inaccessible storage — nothing to show, nothing to break.
    }
}

document.addEventListener('contextmenu', (event) => {
    const tile = event.target.closest('[data-folder-tile]');
    if (!tile || event.target.closest('a, button, [role="menu"]')) return;

    const menu = document.getElementById(tile.dataset.folderMenu);
    if (!menu) return;
    event.preventDefault();
    selectClientFolder(tile, { openPreview: false });
    openFolderMenu(menu, tile.closest('[data-folder-shell]')?.querySelector('[data-folder-menu-trigger]'), { x: event.clientX, y: event.clientY });
});

// Unsaved-changes guard: compares a normalized snapshot of the form's actual field values against
// the baseline captured after this script's synchronous form initializers have finished — not a
// `dirty = true` flag flipped on every 'input'/'change'.
// That distinction is what makes programmatic updates (companion CI add/remove rebuilding hidden
// inputs, the Business Check quick-add flow appending+selecting a new <option>, a photo removal
// appending a removed-id hidden input, etc.) behave correctly either way: if they land the form
// back on its original values, closing is silently allowed; if they leave it different from
// baseline, that's exactly the "real change" this guard exists to protect. FormData naturally
// covers every field type that matters here (text/select/textarea, checkboxes, hidden inputs added
// or mutated at runtime, and file inputs — serialized below by name+size+lastModified since File
// objects themselves aren't comparable).
document.querySelectorAll('[data-unsaved-form]').forEach((form) => {
    // An empty <input type="file"> contributes a placeholder File whose `lastModified` is the
    // current timestamp at read time (not a fixed value) — normalized to a constant marker here so
    // an untouched file field doesn't look "changed" purely because time passed between snapshots.
    const snapshotForm = () => JSON.stringify([...new FormData(form).entries()].map(([key, value]) => (
        value instanceof File ? [key, value.size === 0 ? 'file:none' : `file:${value.name}:${value.size}:${value.lastModified}`] : [key, value]
    )));

    let baseline = null;
    let saved = false;

    // This guard appears before several form initializers in this module. Capturing immediately
    // would treat their normal hidden-input/select hydration as a user edit, which is especially
    // visible when navigating between Co-Makers. A microtask runs after the module's synchronous
    // initialization pass but before the user can interact with the form.
    queueMicrotask(() => {
        if (baseline === null) baseline = snapshotForm();
    });

    form.addEventListener('submit', () => { saved = true; });
    // Dispatched whenever the current form state should become the new "nothing to lose" baseline
    // without actually having been saved — e.g. the Business/Residence Check Cancel button (see
    // [data-close-parent-dialog] below) fires this so a discarded edit can't resurface as a stale
    // warning the next time this same iframe document gets reloaded for another record.
    form.addEventListener('unsaved-form-reset', () => { baseline = snapshotForm(); saved = false; });
    window.addEventListener('beforeunload', (event) => {
        if (saved || baseline === null || snapshotForm() === baseline) return;
        event.preventDefault();
        event.returnValue = '';
    });
});

document.querySelectorAll('dialog').forEach((dialog) => {
    dialog.addEventListener('close', () => {
        dialog.querySelectorAll('video').forEach((video) => video.pause());

        // The Residence/Business Check iframe is never actually unloaded when this dialog closes
        // (native <dialog>.close() just hides it — the same document, and its own unsaved-changes
        // baseline, stays alive in memory) — whether it closed via the in-form Cancel button, the
        // dialog's own X, Escape, or a successful save. Resetting its baseline here on every close,
        // not only a deliberate Cancel, is what stops an edit abandoned that way from resurfacing as
        // a "leave site" warning later, when reopening this same dialog for a different record
        // forces that stale iframe to actually navigate. A close right after a genuine save is a
        // no-op here — the form's own 'submit' listener already brought the baseline in line with
        // what was just submitted.
        if (dialog.matches('[data-check-report-dialog]')) {
            dialog.querySelector('[data-check-report-frame]')?.contentDocument
                ?.querySelectorAll('[data-unsaved-form]')
                .forEach((form) => form.dispatchEvent(new Event('unsaved-form-reset')));
        }

        if (dialog.matches('[data-add-business-dialog]')) {
            const templateSelect = dialog.querySelector('[data-add-business-template-select]');
            const templateError = dialog.querySelector('[data-add-business-template-error]');
            templateSelect?.removeAttribute('aria-invalid');
            if (templateSelect) templateSelect.value = '';
            if (templateError) templateError.hidden = true;
        }
        if (dialog.matches('[data-business-report-dialog]') && dialog.dataset.businessSavedReturnUrl) {
            const returnUrl = new URL(dialog.dataset.businessSavedReturnUrl, window.location.href);
            delete dialog.dataset.businessSavedReturnUrl;
            const currentUrl = new URL(window.location.href);
            // Same-page saves already AUTO-UPDATED live the moment they succeeded (see the
            // 'brbi:business-saved' message handler above) — closing the dialog now needs no
            // reload, the Saved Businesses table and Recent Activity are already current. Only a
            // save made for a different person/folder context still navigates there.
            if (currentUrl.pathname !== returnUrl.pathname || currentUrl.search !== returnUrl.search) {
                window.location.assign(returnUrl.href);
            }
            return;
        }

        if (dialog.matches('[data-check-report-dialog]') && dialog.dataset.checkSavedReturnUrl) {
            const returnUrl = new URL(dialog.dataset.checkSavedReturnUrl, window.location.href);
            delete dialog.dataset.checkSavedReturnUrl;
            const currentUrl = new URL(window.location.href);
            if (currentUrl.pathname === returnUrl.pathname && currentUrl.search === returnUrl.search) {
                window.location.reload();
            } else {
                window.location.assign(returnUrl.href);
            }
            return;
        }

        if (!dialog.matches('[data-cibi-report-dialog]') || !dialog.dataset.cibiSavedReturnUrl) return;

        const returnUrl = new URL(dialog.dataset.cibiSavedReturnUrl, window.location.href);
        delete dialog.dataset.cibiSavedReturnUrl;
        // Same-page saves already AUTO-UPDATED live the moment they succeeded (see the
        // 'brbi:cibi-saved' message handler above) — closing the dialog now needs no reload. Only a
        // save made for a different person/folder context still navigates there.
        const currentUrl = new URL(window.location.href);
        if (currentUrl.pathname !== returnUrl.pathname || currentUrl.search !== returnUrl.search) {
            window.location.assign(returnUrl.href);
        }
    });
});

const repeaterRemoveDialog = document.querySelector('[data-repeater-remove-dialog]');
let pendingRepeaterRemoval = null;

const removeRepeaterRow = (row, repeater) => {
    const id = row.querySelector('input[name$="[id]"]')?.value;
    if (id) {
        row.querySelector('[data-delete-field]').value = '1';
        row.hidden = true;
    } else {
        row.remove();
    }
    repeater.dispatchEvent(new Event('input', { bubbles: true }));
};

const repeaterRowHasData = (row, repeater) => {
    const savedId = row.querySelector('input[name$="[id]"]')?.value;
    if (savedId) return true;
    const selector = repeater.matches('[data-empty-row-remove-without-confirmation]')
        ? 'input:not([type="hidden"]), select, textarea'
        : 'input, select, textarea';

    return [...row.querySelectorAll(selector)].some((field) => {
    if (field.matches('[name$="[id]"], [name$="[_delete]"]')) return false;
    if (['checkbox', 'radio'].includes(field.type)) return field.checked;
    return field.value.trim() !== '';
    });
};

repeaterRemoveDialog?.querySelector('[data-repeater-remove-confirm]')?.addEventListener('click', () => {
    if (!pendingRepeaterRemoval) return;
    const { row, repeater } = pendingRepeaterRemoval;
    pendingRepeaterRemoval = null;
    repeaterRemoveDialog.close();
    removeRepeaterRow(row, repeater);
});
repeaterRemoveDialog?.addEventListener('close', () => { pendingRepeaterRemoval = null; });

// Companion CI picker — shared by every "primary CI + companions" form (Business Report,
// Business Check, and any future one): the modal's checkboxes are a local staging area only —
// nothing is written back until "Add Selected" is clicked, and the actual save still happens
// through that record's own normal Save/Update submit (contributor_ids[] hidden inputs point at
// that form via the `form` attribute, so they post alongside every other field). Each instance is
// wired up independently via its own [data-companion-ci-picker] wrapper carrying which dialog id
// it opens, so multiple pickers (one per page) never interfere with each other.
document.querySelectorAll('[data-companion-ci-container]').forEach((container) => {
    const dialogId = container.closest('[data-companion-ci-picker]')?.dataset.companionDialogId;
    const dialog = dialogId ? document.getElementById(dialogId) : null;
    if (!(dialog instanceof HTMLDialogElement)) return;
    const headerFormId = container.dataset.headerFormId;
    const participantList = container.querySelector('[data-companion-participant-list]');
    const hiddenInputs = container.querySelector('[data-companion-hidden-inputs]');
    if (!participantList || !hiddenInputs) return;

    const selectedIds = () => [...hiddenInputs.querySelectorAll('input[name="contributor_ids[]"]')].map((input) => input.value);
    const visibleIds = () => [...participantList.querySelectorAll('[data-companion-participant][data-user-id]')]
        .map((participant) => participant.dataset.userId)
        .filter(Boolean);
    const normalizeIds = (ids) => [...new Set(ids.map((id) => String(id)).filter(Boolean))];

    const writeHiddenInputs = (ids) => {
        hiddenInputs.innerHTML = '';
        normalizeIds(ids).forEach((id) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'contributor_ids[]';
            input.value = id;
            if (headerFormId) input.setAttribute('form', headerFormId);
            hiddenInputs.appendChild(input);
        });
    };

    // Renders "/ Name ×" for each companion, inline after the (always-present, separately
    // markup'd) primary name — e.g. "REY MAGHILOM / ANTHONY YONG × / MARK DELA CRUZ ×".
    const renderParticipants = (ids) => {
        participantList.innerHTML = '';
        ids.forEach((id) => {
            const option = dialog.querySelector(`[data-companion-option][data-user-id="${id}"]`);
            const fullName = option?.dataset.fullName ?? '';
            const item = document.createElement('span');
            item.className = 'flex items-center gap-1';
            item.dataset.companionParticipant = '';
            item.dataset.userId = id;
            const separator = document.createElement('span');
            separator.setAttribute('aria-hidden', 'true');
            separator.textContent = '/';
            const name = document.createElement('span');
            name.dataset.fullName = '';
            name.textContent = fullName;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'inline-flex size-5 shrink-0 items-center justify-center rounded-full border border-danger/40 bg-danger-soft text-[0.7rem] font-bold normal-case leading-none text-danger transition hover:border-danger hover:bg-danger hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger/40';
            remove.dataset.companionRemove = '';
            remove.setAttribute('aria-label', `Remove ${fullName}`);
            remove.innerHTML = '&times;';
            item.append(separator, name, remove);
            participantList.appendChild(item);
        });
    };

    const setSelection = (ids) => {
        const normalizedIds = normalizeIds(ids);
        writeHiddenInputs(normalizedIds);
        renderParticipants(normalizedIds);
        // Programmatic DOM changes don't fire a native `input` event on their own — nudge the
        // unsaved-changes tracker on data-unsaved-form so leaving the page after only touching
        // companions still warns, same as editing any other field. The always-present marker
        // input is used as the event target since its `form` property resolves correctly
        // (dispatching directly on the <form> element wouldn't — forms have no `.form` property).
        container.querySelector('input[name="contributor_ids_present"]')?.dispatchEvent(new Event('input', { bubbles: true }));
    };

    const form = headerFormId ? document.getElementById(headerFormId) : container.closest('form');
    if (form instanceof HTMLFormElement) {
        const methodOverride = form.querySelector('input[name="_method"]');
        // The confirmed inline participant list is the user-visible source of truth. Rebuild the
        // associated hidden controls at submit time, then reconcile FormData as it is constructed,
        // so add/remove/replace â€” including an explicitly empty list â€” cannot post stale IDs.
        // Preserve Laravel's method override explicitly: this form is transported as POST, and
        // without `_method=PUT` that URL has no matching route and returns 404.
        form.addEventListener('submit', () => { writeHiddenInputs(visibleIds()); });
        form.addEventListener('formdata', (event) => {
            const ids = normalizeIds(visibleIds());
            event.formData.delete('contributor_ids[]');
            ids.forEach((id) => event.formData.append('contributor_ids[]', id));
            event.formData.set('contributor_ids_present', '1');
            if (methodOverride instanceof HTMLInputElement) event.formData.set('_method', methodOverride.value);
        });
    }

    container.addEventListener('click', (event) => {
        const removeTrigger = event.target.closest('[data-companion-remove]');
        if (!removeTrigger) return;
        const id = removeTrigger.closest('[data-companion-participant]')?.dataset.userId;
        if (!id) return;
        const checkbox = dialog.querySelector(`[data-companion-checkbox][value="${id}"]`);
        if (checkbox instanceof HTMLInputElement) checkbox.checked = false;
        setSelection(selectedIds().filter((existingId) => existingId !== id));
    });

    dialog.querySelector('[data-companion-confirm]')?.addEventListener('click', () => {
        const chosen = [...dialog.querySelectorAll('[data-companion-checkbox]:checked')].map((checkbox) => checkbox.value);
        setSelection(chosen);
        dialog.close();
    });
});

// Every time a picker opens, discard whatever was toggled the last time it was cancelled and
// re-sync its checkboxes from the currently confirmed (saved-in-form) companion list instead.
document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-companion-dialog-trigger]');
    if (!trigger) return;
    const picker = trigger.closest('[data-companion-ci-picker]');
    const dialogId = picker?.dataset.companionDialogId;
    const dialog = dialogId ? document.getElementById(dialogId) : null;
    const container = picker?.querySelector('[data-companion-ci-container]');
    if (!(dialog instanceof HTMLDialogElement) || !container) return;
    const selected = [...container.querySelectorAll('[data-companion-hidden-inputs] input[name="contributor_ids[]"]')].map((input) => input.value);
    dialog.querySelectorAll('[data-companion-checkbox]').forEach((checkbox) => { checkbox.checked = selected.includes(checkbox.value); });
    dialog.querySelectorAll('[data-companion-option]').forEach((option) => { option.hidden = false; });
    const search = dialog.querySelector('[data-companion-search]');
    if (search) search.value = '';
    const searchEmpty = dialog.querySelector('[data-companion-search-empty]');
    if (searchEmpty) searchEmpty.hidden = true;
});

document.querySelector('[data-companion-search]')?.addEventListener('input', (event) => {
    const dialog = event.target.closest('dialog');
    if (!dialog) return;
    const value = event.target.value.trim().toLowerCase();
    let visibleCount = 0;
    dialog.querySelectorAll('[data-companion-option]').forEach((option) => {
        const matches = (option.dataset.searchName || '').includes(value);
        option.hidden = !matches;
        if (matches) visibleCount++;
    });
    const searchEmpty = dialog.querySelector('[data-companion-search-empty]');
    if (searchEmpty) searchEmpty.hidden = visibleCount !== 0;
});

const initializeBusinessRepeaters = (scope = document) => scope.querySelectorAll('[data-repeater]').forEach((repeater) => {
    if (repeater.dataset.repeaterReady) return;
    repeater.dataset.repeaterReady = 'true';
    const rows = repeater.querySelector('[data-repeater-rows]');
    const template = repeater.querySelector('[data-repeater-template]');
    let nextIndex = rows?.children.length ?? 0;

    repeater.querySelector('[data-repeater-add]')?.addEventListener('click', () => {
        if (!rows || !template) return;
        const row = template.content.firstElementChild?.cloneNode(true);
        if (!row) return;
        [row, ...row.querySelectorAll('*')].forEach((element) => {
            [...element.attributes].forEach((attribute) => {
                if (attribute.value.includes('__INDEX__')) element.setAttribute(attribute.name, attribute.value.replaceAll('__INDEX__', String(nextIndex)));
            });
        });
        if (repeater.closest('[data-business-template-preview-target]')) {
            row.querySelectorAll('input[name], select[name], textarea[name]').forEach((control) => control.setAttribute('form', 'business-template-form'));
        }
        nextIndex++;
        rows.append(row);
        initializeCibiControls(row);
        row.querySelector('input:not([type="hidden"]), select, textarea')?.focus();
        row.dispatchEvent(new Event('input', { bubbles: true }));
    });

    repeater.addEventListener('click', (event) => {
        const button = event.target.closest('[data-repeater-remove]');
        if (!button) return;
        const row = button.closest('[data-repeater-row]');
        if (!row) return;
        if (repeaterRemoveDialog instanceof HTMLDialogElement && repeaterRowHasData(row, repeater)) {
            pendingRepeaterRemoval = { row, repeater };
            repeaterRemoveDialog.showModal();
            repeaterRemoveDialog.querySelector('[data-modal-close]')?.focus();
            return;
        }
        removeRepeaterRow(row, repeater);
    });
});

initializeBusinessRepeaters();

document.addEventListener('input', (event) => {
    const stockroomInput = event.target.closest('[data-distributor-stockroom-input]');
    if (!(stockroomInput instanceof HTMLInputElement)) return;
    const section = stockroomInput.closest('[data-distributor-stockroom]');
    const fieldType = stockroomInput.dataset.distributorStockroomInput;
    const valueField = section?.querySelector(`[data-distributor-stockroom-value="${fieldType}"]`);
    if (!(valueField instanceof HTMLInputElement)) return;
    valueField.value = [...section.querySelectorAll(`[data-distributor-stockroom-input="${fieldType}"]`)]
        .map((field) => field.value.trim())
        .join('\n');
    valueField.dispatchEvent(new Event('input', { bubbles: true }));
});

document.addEventListener('change', (event) => {
    const input = event.target.closest('[data-photo-input]');
    if (!(input instanceof HTMLInputElement)) return;
    const wrap = input.closest('div')?.querySelector('[data-photo-preview-wrap]');
    const img = wrap?.querySelector('[data-photo-preview]');
    const placeholder = wrap?.querySelector('[data-photo-preview-placeholder]');
    if (!(img instanceof HTMLImageElement) || !placeholder) return;
    const file = input.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
        img.src = String(reader.result);
        img.classList.remove('hidden');
        placeholder.setAttribute('hidden', '');
    };
    reader.readAsDataURL(file);
});

document.addEventListener('change', (event) => {
    const option = event.target.closest('[data-distributor-product-option]');
    if (!(option instanceof HTMLInputElement)) return;
    const section = option.closest('[data-distributor-stockroom]');
    const valueField = section?.querySelector('[data-distributor-products-value]');
    if (!(valueField instanceof HTMLInputElement)) return;
    valueField.value = [...section.querySelectorAll('[data-distributor-product-option]:checked')]
        .map((field) => field.value)
        .join(', ');
    valueField.dispatchEvent(new Event('input', { bubbles: true }));
});

const syncDistributorPaymentTerm = (section, clearInactive = false) => {
    const termOption = section.querySelector('[data-distributor-payment-option="term"]');
    const termInput = section.querySelector('[data-distributor-payment-term-input]');
    if (!(termOption instanceof HTMLInputElement) || !(termInput instanceof HTMLInputElement)) return;
    const termSelected = termOption.checked;
    termInput.disabled = !termSelected;
    if (!termSelected && clearInactive) termInput.value = '';
    termOption.value = `TERM:${termInput.value.trim() ? ` ${termInput.value.trim()}` : ''}`;
};

document.addEventListener('change', (event) => {
    const option = event.target.closest('[data-distributor-payment-option]');
    if (!(option instanceof HTMLInputElement)) return;
    const section = option.closest('[data-distributor-payment-terms]');
    if (section instanceof HTMLElement) syncDistributorPaymentTerm(section, true);
});

document.addEventListener('input', (event) => {
    const termInput = event.target.closest('[data-distributor-payment-term-input]');
    if (!(termInput instanceof HTMLInputElement)) return;
    const section = termInput.closest('[data-distributor-payment-terms]');
    if (section instanceof HTMLElement) syncDistributorPaymentTerm(section);
});

document.addEventListener('input', (event) => {
    const display = event.target.closest('[data-property-boolean-display]');
    if (!(display instanceof HTMLInputElement)) return;
    const valueField = display.previousElementSibling;
    if (!(valueField instanceof HTMLInputElement) || !valueField.matches('[data-property-boolean-value]')) return;
    const value = display.value.trim().toUpperCase();
    valueField.value = value === 'Y' ? '1' : (value === 'N' ? '0' : '');
});

document.addEventListener('input', (event) => {
    const summaryReason = event.target.closest('[data-property-summary-reason]');
    if (!(summaryReason instanceof HTMLInputElement)) return;
    const section = summaryReason.closest('.business-non-agricultural-properties');
    const reasonField = section?.querySelector('[data-repeater-row]:not([hidden]) [data-property-reason-value]');
    if (reasonField instanceof HTMLInputElement) reasonField.value = summaryReason.value;
});

const initializeBusinessAddressStatus = (scope = document) => scope.querySelectorAll('[data-business-address-from]').forEach((fromField) => {
    if (fromField.dataset.businessAddressStatusReady) return;
    fromField.dataset.businessAddressStatusReady = 'true';
    const container = fromField.closest('.business-address-status');
    const statuses = [...(container?.querySelectorAll('[data-business-address-status]') ?? [])];
    const monthlyRentField = container?.querySelector('[data-business-monthly-rent]');
    const sync = () => {
        const status = statuses.find((option) => option.checked)?.value;
        const mortgageeOrLessorApplicable = ['Mortgaged', 'Rented'].includes(status);
        fromField.disabled = !mortgageeOrLessorApplicable;
        if (!mortgageeOrLessorApplicable) fromField.value = '';
        if (monthlyRentField instanceof HTMLInputElement) monthlyRentField.disabled = status !== 'Rented';
    };
    statuses.forEach((option) => option.addEventListener('change', sync));
    sync();
});

initializeBusinessAddressStatus();

const initializeOtherIncomeSourceSummary = (root = document) => {
    root.querySelectorAll('[data-other-income-source]').forEach((section) => {
        if (section.dataset.summaryReady === 'true') return;
        section.dataset.summaryReady = 'true';
        const summary = section.querySelector('[data-income-source-summary]');
        const empty = section.querySelector('[data-income-source-empty]');
        if (!(summary instanceof HTMLOListElement)) return;

        const sync = () => {
            const selected = [...section.querySelectorAll('[data-income-source-choice]:checked')];
            summary.replaceChildren(...selected.map((control) => {
                const item = document.createElement('li');
                const label = control.dataset.incomeSourceLabel || 'Income source';
                item.textContent = label;
                return item;
            }));
            if (empty instanceof HTMLElement) empty.hidden = selected.length > 0;
        };

        section.addEventListener('input', sync);
        section.addEventListener('change', sync);
        sync();
    });
};

initializeOtherIncomeSourceSummary();

document.querySelectorAll('[data-business-template-title]').forEach((chooser) => {
    const select = chooser.querySelector('[data-business-template-select]');
    const page = chooser.closest('[data-business-report-form]');
    const previewTarget = page?.querySelector('[data-business-template-preview-target]');
    const currentForm = page?.querySelector('[data-current-business-form]');
    const currentToolbar = page?.querySelector('[data-current-business-toolbar]');
    const saveToolbar = document.querySelector('[data-business-save-toolbar]');
    const saveButton = saveToolbar?.querySelector('[data-business-save]');
    const headerControls = page?.querySelectorAll('.business-report-header-control[name]') ?? [];
    const switchDialog = document.querySelector('[data-business-template-switch-dialog]');
    const switchConfirm = switchDialog?.querySelector('[data-business-template-switch-confirm]');
    if (!(select instanceof HTMLSelectElement) || !previewTarget || !currentForm) return;

    let activeTemplateId = select.value;
    let pendingTemplateId = null;
    let hasUnsavedTemplateData = false;

    const showSelectedTemplate = () => {
        previewTarget.replaceChildren();
        const template = document.querySelector(`[data-business-template-preview="${CSS.escape(select.value)}"]`);
        const hasSelection = template instanceof HTMLTemplateElement;
        currentForm.hidden = hasSelection;
        if (currentToolbar) currentToolbar.hidden = hasSelection;
        if (saveToolbar) saveToolbar.hidden = !hasSelection && !page.matches('form');
        if (saveButton) saveButton.setAttribute('form', hasSelection ? 'business-template-form' : (page.matches('form') ? 'business-report-form' : 'business-template-form'));
        previewTarget.hidden = !hasSelection;
        headerControls.forEach((control) => control.setAttribute('form', hasSelection ? 'business-template-form' : (page.matches('form') ? 'business-report-form' : 'business-template-form')));
        if (!hasSelection) return;

        const content = template.content.cloneNode(true);
        content.querySelectorAll('input[name], select[name], textarea[name]').forEach((control) => control.setAttribute('form', 'business-template-form'));
        previewTarget.append(content);
        initializeBusinessRepeaters(previewTarget);
        initializeBusinessAddressStatus(previewTarget);
        initializeOtherIncomeSourceSummary(previewTarget);
        previewTarget.animate?.([{ opacity: 0, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'translateY(0)' }], { duration: 180, easing: 'ease-out' });
    };

    page.addEventListener('input', (event) => {
        const control = event.target;
        if (control instanceof HTMLElement && control.matches('[data-repeater]')) {
            hasUnsavedTemplateData = true;
            return;
        }
        if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) return;
        if (control === select || control.readOnly || control.disabled || control.type === 'hidden') return;
        hasUnsavedTemplateData = true;
    });
    page.addEventListener('change', (event) => {
        const control = event.target;
        if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) return;
        if (control === select || control.readOnly || control.disabled || control.type === 'hidden') return;
        hasUnsavedTemplateData = true;
    });

    select.addEventListener('change', () => {
        const requestedTemplateId = select.value;
        if (!hasUnsavedTemplateData || requestedTemplateId === activeTemplateId || !(switchDialog instanceof HTMLDialogElement)) {
            activeTemplateId = requestedTemplateId;
            hasUnsavedTemplateData = false;
            showSelectedTemplate();
            return;
        }

        pendingTemplateId = requestedTemplateId;
        select.value = activeTemplateId;
        switchDialog.showModal();
        switchDialog.querySelector('[data-modal-close]')?.focus();
    });
    switchConfirm?.addEventListener('click', () => {
        if (pendingTemplateId === null) return;
        activeTemplateId = pendingTemplateId;
        pendingTemplateId = null;
        hasUnsavedTemplateData = false;
        select.value = activeTemplateId;
        if (page instanceof HTMLFormElement) page.dispatchEvent(new Event('unsaved-form-reset'));
        switchDialog.close();
        showSelectedTemplate();
        select.focus();
    });
    switchDialog?.addEventListener('close', () => {
        pendingTemplateId = null;
        select.value = activeTemplateId;
    });
    showSelectedTemplate();
});

const initializeCibiControls = (scope = document) => {
    scope.querySelectorAll('[data-number-format]').forEach((field) => {
        if (field.dataset.numberFormatReady) return;
        field.dataset.numberFormatReady = 'true';
        const format = () => {
            const raw = field.value.replaceAll(',', '').trim();
            if (raw === '' || Number.isNaN(Number(raw))) return;
            const [whole, fraction] = raw.split('.');
            field.value = Number(whole).toLocaleString('en-US') + (fraction !== undefined ? `.${fraction}` : '');
        };
        field.addEventListener('focus', () => { field.value = field.value.replaceAll(',', ''); });
        field.addEventListener('blur', format);
        format();
    });

    scope.querySelectorAll('[data-adb-figures]').forEach((figures) => { figures.disabled = false; });
};

initializeCibiControls();

document.querySelectorAll('[data-photo-sections-form]').forEach((form) => {
    const rows = form.querySelector('[data-photo-section-rows]');
    const template = form.querySelector('[data-photo-section-template]');
    let nextIndex = rows?.children.length ?? 0;

    const filterMedia = (section) => {
        const category = section.dataset.category;
        const source = section.querySelector('[data-section-income-source]')?.value ?? '';
        section.querySelectorAll('[data-section-media]').forEach((item) => {
            const categoryMatches = item.dataset.mediaCategory === category;
            const sourceMatches = category !== 'business' || !item.dataset.mediaIncomeSource || item.dataset.mediaIncomeSource === source;
            item.hidden = !categoryMatches || !sourceMatches;
            if (item.hidden) item.querySelector('input[type="checkbox"]').checked = false;
        });
    };

    form.querySelectorAll('[data-photo-section]').forEach(filterMedia);
    form.addEventListener('change', (event) => {
        if (event.target.matches('[data-section-income-source]')) filterMedia(event.target.closest('[data-photo-section]'));
    });
    form.addEventListener('click', (event) => {
        const add = event.target.closest('[data-photo-section-add]');
        if (add && rows && template) {
            const category = add.dataset.photoSectionAdd;
            const label = category === 'business' ? 'Business' : 'Residence';
            const wrapper = document.createElement('div');
            wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++)).replaceAll('__CATEGORY__', category).replaceAll('__CATEGORY_LABEL__', label);
            const section = wrapper.firstElementChild;
            if (section) {
                section.dataset.category = category;
                section.querySelector('[data-section-category]').value = category;
                section.querySelector('[data-section-category-label]').textContent = label;
                section.querySelector('[data-section-heading]').value = category === 'business' ? 'Business Check' : 'Residence Check';
                section.querySelectorAll('[data-section-business-only]').forEach((element) => { element.hidden = category !== 'business'; });
                rows.append(section);
                filterMedia(section);
                section.querySelector('input:not([type="hidden"]), select, textarea')?.focus();
                section.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
        const remove = event.target.closest('[data-photo-section-remove]');
        if (remove) {
            const section = remove.closest('[data-photo-section]');
            const id = section?.querySelector('input[name$="[id]"]')?.value;
            if (!section) return;
            if (id) {
                section.querySelector('[data-delete-field]').value = '1';
                section.hidden = true;
            } else {
                section.remove();
            }
            form.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
});

document.addEventListener('close', (event) => {
    if (!(event.target instanceof HTMLDialogElement)) return;
    const returnFocus = event.target.dataset.returnFocus;
    if (returnFocus) document.getElementById(returnFocus)?.focus();
}, true);

document.addEventListener('click', (event) => {
    const navigation = event.target.closest('[data-media-previous], [data-media-next]');
    if (!navigation) return;

    const current = navigation.closest('dialog[data-media-preview]');
    if (!(current instanceof HTMLDialogElement)) return;
    const previews = [...document.querySelectorAll('dialog[data-media-preview]')];
    const currentIndex = previews.indexOf(current);
    if (currentIndex < 0 || previews.length < 2) return;

    const direction = navigation.matches('[data-media-next]') ? 1 : -1;
    const next = previews[(currentIndex + direction + previews.length) % previews.length];
    current.close();
    next.showModal();
    next.querySelector('[data-modal-close]')?.focus();
});

document.querySelectorAll('dialog[data-open-on-error="true"]').forEach((dialog) => {
    if (!(dialog instanceof HTMLDialogElement)) return;
    dialog.showModal();
    dialog.querySelector('[autofocus]')?.focus();
});

document.addEventListener('toggle', (event) => {
    const menu = event.target;
    if (!(menu instanceof HTMLDetailsElement) || !menu.matches('[data-context-menu]')) return;

    menu.querySelector(':scope > summary')?.setAttribute('aria-expanded', String(menu.open));
    if (!menu.open) return;

    document.querySelectorAll('details[data-context-menu][open]').forEach((otherMenu) => {
        if (otherMenu !== menu) closeContextMenu(otherMenu);
    });
}, true);

document.addEventListener('keydown', (event) => {
    const openMenu = event.target.closest('[data-folder-action-menu]');
    if (openMenu && ['ArrowDown', 'ArrowUp', 'Home', 'End', 'Escape'].includes(event.key)) {
        const items = [...openMenu.querySelectorAll('[role="menuitem"]')];
        const current = items.indexOf(event.target);
        if (event.key === 'Escape') closeFolderMenus({ restoreFocus: true });
        if (event.key === 'ArrowDown') items[(current + 1) % items.length]?.focus();
        if (event.key === 'ArrowUp') items[(current - 1 + items.length) % items.length]?.focus();
        if (event.key === 'Home') items[0]?.focus();
        if (event.key === 'End') items.at(-1)?.focus();
        event.preventDefault();
        return;
    }

    const tile = event.target.closest('[data-folder-tile]');
    if (tile && event.target === tile) {
        if (event.key === 'Enter') {
            window.location.assign(tile.dataset.folderOpenUrl);
            event.preventDefault();
            return;
        }
        if (event.key === ' ') {
            selectClientFolder(tile);
            event.preventDefault();
            return;
        }
        if (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) {
            selectClientFolder(tile);
            const menu = document.getElementById(tile.dataset.folderMenu);
            if (menu) openFolderMenu(menu, tile.querySelector('[data-folder-menu-trigger]'));
            event.preventDefault();
            return;
        }
    }

    if (event.key === 'Escape') {
        document.querySelectorAll('details[data-context-menu][open]').forEach((menu) => {
            closeContextMenu(menu, { restoreFocus: true });
        });
        closeFolderMenus({ restoreFocus: true });
        document.querySelectorAll('[data-folder-browser]').forEach(closeFolderPreview);
        setDrawer(false);
    }

    const tab = event.target.closest('[role="tab"]');
    if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;

    const tabs = [...tab.closest('[role="tablist"]').querySelectorAll('[role="tab"]')];
    let index = tabs.indexOf(tab);
    if (event.key === 'ArrowRight') index = (index + 1) % tabs.length;
    if (event.key === 'ArrowLeft') index = (index - 1 + tabs.length) % tabs.length;
    if (event.key === 'Home') index = 0;
    if (event.key === 'End') index = tabs.length - 1;
    tabs[index].click();
    tabs[index].focus();
    event.preventDefault();
});

document.addEventListener('click', (event) => {
    const tab = event.target.closest('[role="tab"]');
    if (!tab) return;

    const tablist = tab.closest('[role="tablist"]');
    const container = tablist.closest('[data-tabs]');
    tablist.querySelectorAll('[role="tab"]').forEach((item) => {
        const selected = item === tab;
        item.setAttribute('aria-selected', String(selected));
        item.tabIndex = selected ? 0 : -1;
    });
    container.querySelectorAll('[role="tabpanel"]').forEach((panel) => {
        panel.hidden = panel.id !== tab.getAttribute('aria-controls');
    });
});

document.querySelectorAll('[data-cibi-form]').forEach((form) => {
    const errorSummary = form.querySelector('[data-cibi-error-summary]');
    let saving = false;

    const residenceStatuses = [...form.querySelectorAll('[data-residence-status]')];
    const residenceFromDisplay = form.querySelector('[data-residence-from-display]');
    const residenceFromValue = form.querySelector('[data-residence-from-value]');
    const monthlyRentDisplay = form.querySelector('[data-monthly-rent-display]');
    const monthlyRentValue = form.querySelector('[data-monthly-rent-value]');
    const syncResidenceFields = () => {
        const status = residenceStatuses.find((option) => option.checked)?.value;
        const fromApplicable = ['Mortgaged', 'Rented'].includes(status);
        const rented = status === 'Rented';
        if (residenceFromDisplay && residenceFromValue) {
            residenceFromDisplay.disabled = !fromApplicable;
            if (!fromApplicable) residenceFromDisplay.value = residenceFromValue.value = '';
            else if (residenceFromDisplay.value === 'N/A') residenceFromDisplay.value = residenceFromValue.value = '';
        }
        if (monthlyRentDisplay && monthlyRentValue) {
            monthlyRentDisplay.disabled = !rented;
            if (!rented) monthlyRentDisplay.value = monthlyRentValue.value = '';
            else if (monthlyRentDisplay.value === 'N/A') monthlyRentDisplay.value = monthlyRentValue.value = '';
        }
    };
    residenceStatuses.forEach((option) => option.addEventListener('change', syncResidenceFields));
    residenceFromDisplay?.addEventListener('input', () => { residenceFromValue.value = residenceFromDisplay.value; });
    monthlyRentDisplay?.addEventListener('input', () => { monthlyRentValue.value = monthlyRentDisplay.value; });
    const presentAddress = form.querySelector('[data-present-address]');
    const addressSuggestions = [...form.querySelectorAll('[data-copy-present-address]')];
    const normalizeAddress = (value) => value.toLocaleLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim().replace(/\s+/g, ' ');
    const addressMatches = (typed, available) => {
        const query = normalizeAddress(typed);
        const present = normalizeAddress(available);
        if (!query || !present || query === present) return false;
        if (present.includes(query)) return true;
        const presentWords = present.split(' ');
        return query.split(' ').every((word) => presentWords.some((presentWord) => presentWord.startsWith(word)));
    };
    const syncAddressSuggestions = () => {
        const suggestion = presentAddress?.value.trim() || '';
        addressSuggestions.forEach((button) => {
            const target = document.getElementById(button.dataset.copyPresentAddress);
            const matches = target && addressMatches(target.value, suggestion);
            button.hidden = !matches;
            button.disabled = !matches;
            const value = button.querySelector('[data-present-address-suggestion]');
            if (value) value.textContent = suggestion;
        });
    };
    addressSuggestions.forEach((button) => button.addEventListener('click', () => {
        const suggestion = presentAddress?.value.trim() || '';
        const target = document.getElementById(button.dataset.copyPresentAddress);
        if (!suggestion || !target) return;
        target.value = suggestion;
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.focus();
    }));
    presentAddress?.addEventListener('input', syncAddressSuggestions);
    addressSuggestions.forEach((button) => document.getElementById(button.dataset.copyPresentAddress)?.addEventListener('input', syncAddressSuggestions));
    syncAddressSuggestions();
    syncResidenceFields();

    const clearErrors = () => {
        form.querySelectorAll('[aria-invalid="true"]').forEach((field) => {
            field.removeAttribute('aria-invalid');
            field.removeAttribute('aria-describedby');
        });
        form.querySelectorAll('[data-cibi-field-error]').forEach((error) => error.remove());
        if (errorSummary) errorSummary.hidden = true;
    };

    const htmlFieldName = (key) => {
        const [root, ...parts] = key.split('.');
        return root + parts.map((part) => `[${part}]`).join('');
    };

    const showErrors = (errors, message = 'No report changes were saved.') => {
        clearErrors();
        if (errorSummary) {
            errorSummary.hidden = false;
            errorSummary.querySelector('[data-cibi-error-message]').textContent = message;
        }

        let firstInvalid = null;
        Object.entries(errors).forEach(([key, messages]) => {
            const htmlName = htmlFieldName(key);
            const field = form.querySelector(`[name="${CSS.escape(htmlName)}"]`)
                || form.querySelector(`[name="${CSS.escape(htmlName + '[]')}"]`);
            // A hidden field (e.g. expected_revision) can't usefully receive an inline error or
            // focus — its message is already shown prominently in the error summary banner above.
            if (!field || field.type === 'hidden') return;
            const id = `${field.id || key.replaceAll('.', '-')}-error`;
            const error = document.createElement('p');
            error.id = id;
            error.dataset.cibiFieldError = '';
            error.className = 'cibi-field-error';
            error.setAttribute('role', 'alert');
            error.textContent = Array.isArray(messages) ? messages[0] : messages;
            field.setAttribute('aria-invalid', 'true');
            field.setAttribute('aria-describedby', id);
            (field.closest('div, fieldset') || field).append(error);
            firstInvalid ||= field;
        });

        (firstInvalid || errorSummary)?.focus();
        (firstInvalid || errorSummary)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    const showToast = (message, type = 'success', duration = 2500) => {
        const region = document.querySelector('[data-toast-region]');
        if (!region) return;
        const toneClass = type === 'info' ? 'border-progress/25 bg-progress-soft text-progress' : 'border-success/25 bg-success-soft text-success';
        const toast = document.createElement('div');
        toast.dataset.toast = '';
        toast.className = `flex items-start gap-3 rounded-card border p-4 shadow-card ${toneClass}`;
        toast.setAttribute('role', 'status');
        const text = document.createElement('p');
        text.className = 'min-w-0 flex-1 text-sm font-semibold';
        text.textContent = message;
        const close = document.createElement('button');
        close.type = 'button';
        close.dataset.toastClose = '';
        close.className = '-m-2 rounded p-2';
        close.setAttribute('aria-label', 'Dismiss message');
        close.textContent = '×';
        toast.append(text, close);
        region.append(toast);
        window.setTimeout(() => toast.remove(), duration);
    };

    const updateOverview = (payload) => {
        form.querySelector('[data-cibi-revision]')?.replaceChildren(String(payload.report.revision));
        const expectedRevisionInput = form.querySelector('[data-cibi-expected-revision]');
        if (expectedRevisionInput) expectedRevisionInput.value = String(payload.report.revision);
        Object.entries(payload.report.child_ids || {}).forEach(([section, ids]) => {
            const repeater = form.querySelector(`[data-repeater="${CSS.escape(section)}"]`);
            const rows = [...(repeater?.querySelector('[data-repeater-rows]')?.children || [])];
            const rowIds = payload.report.child_row_ids?.[section];
            if (rowIds) {
                rows.forEach((row, index) => {
                    if (row.querySelector('[data-delete-field]')?.value === '1') {
                        row.remove();
                        return;
                    }
                    const idField = row.querySelector('input[name$="[id]"]');
                    if (idField) idField.value = rowIds[index] === undefined ? '' : String(rowIds[index]);
                });
                return;
            }
            const activeRows = [];
            rows.forEach((row) => {
                if (row.querySelector('[data-delete-field]')?.value === '1') {
                    row.remove();
                    return;
                }
                activeRows.push(row);
            });
            activeRows.forEach((row, index) => {
                const id = ids[index];
                const idField = row.querySelector('input[name$="[id]"]');
                if (idField && id !== undefined) idField.value = String(id);
            });
        });
        const outputActions = form.querySelector('[data-cibi-output-actions]');
        if (outputActions && payload.report.state === 'complete') outputActions.hidden = false;
        const submitButton = form.querySelector('[data-cibi-submit]');
        submitButton?.replaceChildren(payload.report.submit_label || 'Update');
        if (submitButton) submitButton.dataset.cibiSubmitMode = 'update';
        const totals = {
            checked: payload.report.institutions_checked,
            declared: payload.report.institutions_declared,
            loans: payload.report.loan_records_found,
        };
        Object.entries(totals).forEach(([key, value]) => {
            const target = form.querySelector(`[data-cibi-total="${key}"]`);
            if (!target) return;
            if (target.matches('input')) target.value = String(value);
            else target.replaceChildren(String(value));
        });
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (saving) return;
        saving = true;
        clearErrors();

        const submitter = event.submitter;
        const intent = submitter?.value || 'complete';
        form.querySelector('[data-cibi-intent]').value = intent;
        const buttons = [...form.querySelectorAll('[data-cibi-submit]')];
        buttons.forEach((button) => { button.disabled = true; });

        try {
            const formData = new FormData(form);
            form.querySelectorAll('[data-number-format][name]').forEach((field) => formData.set(field.name, field.value.replaceAll(',', '')));
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const payload = await response.json();
            if (!response.ok) {
                // A revision conflict is the one validation error the user must see prominently —
                // its field (expected_revision) is hidden, so the generic top-level message would
                // otherwise hide the specific "updated by another user" wording entirely.
                const conflictMessage = payload.errors?.expected_revision?.[0];
                showErrors(payload.errors || {}, conflictMessage || payload.message || 'The report could not be saved.');
                return;
            }

            if (payload.no_change) {
                showToast(payload.message, 'info', 3500);
                return;
            }

            updateOverview(payload);
            // Inside the CI/BI dialog's iframe (the only way this form is ever loaded), the local
            // toast below would be destroyed the instant the parent closes the dialog before anyone
            // could read it — so the success message travels to the parent's own persistent toast
            // region instead, alongside the dialog auto-close, via the existing 'brbi:cibi-saved'
            // message handler. A standalone load (window.parent === window) has no parent to hand
            // it to and shows it locally, same as before.
            if (window.parent !== window) {
                window.parent.postMessage({
                    type: 'brbi:cibi-saved',
                    returnUrl: payload.return_url,
                    folder: payload.folder,
                    message: payload.message,
                    statusType: 'success',
                    cibiModuleHtml: payload.cibi_module_html,
                    recentActivityHtml: payload.recent_activity_html,
                }, window.location.origin);
            } else {
                showToast(payload.message);
            }
        } catch {
            showErrors({}, 'The report could not be saved. Check your connection and try again.');
        } finally {
            saving = false;
            buttons.forEach((button) => { button.disabled = false; });
        }
    });

});

document.addEventListener('click', (event) => {
    const addTrigger = event.target.closest('[data-co-maker-add-trigger]');
    const editTrigger = event.target.closest('[data-co-maker-edit-trigger]');
    if (!addTrigger && !editTrigger) return;

    const form = document.querySelector('[data-co-maker-form]');
    const submit = document.querySelector('[data-co-maker-submit]');
    const title = document.getElementById('co-maker-dialog-title');
    const idField = form?.querySelector('[data-co-maker-id-field]');
    if (!form) return;

    form.reset();
    if (idField) idField.value = '';
    form.querySelectorAll('[data-co-maker-error-for]').forEach((error) => {
        error.textContent = '';
        error.hidden = true;
    });
    form.querySelectorAll('[aria-invalid="true"]').forEach((field) => field.removeAttribute('aria-invalid'));

    if (editTrigger) {
        // Every edit trigger (header quick-edit, or a specific co-maker's tab menu) carries its
        // own record's data directly, so the form always loads the exact co-maker that was
        // clicked — never whichever one happens to be shown elsewhere on the page.
        const { coMakerId, coMakerFirstName, coMakerMiddleName, coMakerLastName, coMakerSuffix, coMakerAddress } = editTrigger.dataset;
        if (idField) idField.value = coMakerId ?? '';
        form.elements.namedItem('first_name').value = coMakerFirstName ?? '';
        form.elements.namedItem('middle_name').value = coMakerMiddleName ?? '';
        form.elements.namedItem('last_name').value = coMakerLastName ?? '';
        form.elements.namedItem('suffix').value = coMakerSuffix ?? '';
        form.elements.namedItem('address').value = coMakerAddress ?? '';
        if (submit) submit.textContent = 'Update Co-Maker';
        if (title) title.textContent = 'Edit Co-Maker';
    } else {
        if (submit) submit.textContent = 'Save Co-Maker';
        if (title) title.textContent = 'Add Co-Maker';
    }
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-co-maker-form]');
    if (!form) return;
    event.preventDefault();

    form.querySelectorAll('[data-co-maker-error-for]').forEach((error) => {
        error.textContent = '';
        error.hidden = true;
    });
    form.querySelectorAll('[aria-invalid="true"]').forEach((field) => field.removeAttribute('aria-invalid'));

    const submit = document.querySelector('[data-co-maker-submit]');
    submit?.setAttribute('disabled', 'disabled');

    try {
        // The current page's own ?person=co-maker&co_maker_id=... travels with the request so the
        // backend renders the person-switch tabs / Recent Activity from the exact same active
        // context the CI is looking at — the co-maker being added/edited here isn't necessarily
        // that same person.
        const submitUrl = new URL(form.action, window.location.href);
        submitUrl.search = window.location.search;
        const response = await fetch(submitUrl, {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const payload = await response.json();

        if (response.status === 422) {
            let firstInvalid;
            Object.entries(payload.errors ?? {}).forEach(([fieldName, messages]) => {
                const field = form.elements.namedItem(fieldName);
                const error = form.querySelector(`[data-co-maker-error-for="${fieldName}"]`);
                if (field instanceof HTMLElement) {
                    field.setAttribute('aria-invalid', 'true');
                    firstInvalid ??= field;
                }
                if (error) {
                    error.textContent = messages[0] ?? 'Enter a valid value.';
                    error.hidden = false;
                }
            });
            firstInvalid?.focus();
            return;
        }
        if (!response.ok) throw new Error(payload.message || 'Co-Maker could not be saved.');

        form.closest('dialog')?.close();

        if (payload.no_change) {
            showToast(payload.message, 'info', 3500);
            return;
        }

        // AUTO-UPDATE from this exact same authoritative response — the person-switch tabs (new/
        // renamed Co-Maker) and Recent Activity (fresh co_maker.added/updated entry) are both
        // server-rendered fragments, never a second GET and never a reload.
        const personSwitchRegion = document.querySelector('[data-person-switch-region]');
        if (personSwitchRegion && typeof payload.person_switch_html === 'string') personSwitchRegion.innerHTML = payload.person_switch_html;
        const recentActivityBody = document.querySelector('[data-recent-activity-body]');
        if (recentActivityBody && typeof payload.recent_activity_html === 'string') recentActivityBody.innerHTML = payload.recent_activity_html;

        showToast(payload.message, 'success', 3000);
    } catch (error) {
        showToast(error.message || 'Co-Maker could not be saved. Please retry.', 'error', 3000);
    } finally {
        submit?.removeAttribute('disabled');
    }
});

document.addEventListener('click', (event) => {
    const removeTrigger = event.target.closest('[data-co-maker-remove-trigger]');
    if (!removeTrigger) return;

    // Each remove trigger — the modal footer's (synced when an edit loads) and every co-maker
    // tab's own menu item — carries its own record's id/name/base-url, so removal always targets
    // the exact co-maker that was clicked regardless of which one is currently active on screen.
    const { coMakerId, coMakerFullName, coMakerDestroyBaseUrl } = removeTrigger.dataset;
    if (!coMakerId || !coMakerDestroyBaseUrl) return;

    const removeDialog = document.getElementById('co-maker-remove-dialog');
    const removeForm = removeDialog?.querySelector('[data-co-maker-remove-form]');
    if (!(removeDialog instanceof HTMLDialogElement) || !(removeForm instanceof HTMLFormElement)) return;

    removeForm.action = `${coMakerDestroyBaseUrl}/${coMakerId}`;
    removeForm.dataset.coMakerRemoveId = coMakerId;
    removeDialog.querySelector('[data-co-maker-remove-name]').textContent = coMakerFullName || 'this co-maker';

    removeTrigger.closest('dialog')?.close();
    removeDialog.showModal();
    removeDialog.querySelector('[data-modal-close], [autofocus]')?.focus();
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-co-maker-remove-form]');
    if (!form) return;
    event.preventDefault();

    const submit = document.querySelector('[data-co-maker-remove-submit]');
    submit?.setAttribute('disabled', 'disabled');

    // Removing the currently active co-maker leaves nothing valid for the URL's ?co_maker_id to
    // point at — checked before the request fires so it still reflects the page the CI was
    // actually viewing, not anything the response could change.
    const removedId = form.dataset.coMakerRemoveId ?? '';
    const activeId = new URLSearchParams(window.location.search).get('co_maker_id');
    const removingActivePerson = Boolean(removedId) && removedId === activeId;

    try {
        const submitUrl = new URL(form.action, window.location.href);
        submitUrl.search = window.location.search;
        const response = await fetch(submitUrl, {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message || 'Co-Maker could not be removed.');

        form.closest('dialog')?.close();
        showToast(payload.message, 'success', 3000);

        if (removingActivePerson) {
            // The active person's own context is gone — no fragment can meaningfully represent
            // it, so this is a genuine navigation (not a disguised refresh) back to the canonical
            // Applicant view, exactly as before.
            window.location.assign(window.location.pathname);
            return;
        }

        // AUTO-UPDATE from this exact same authoritative response — removing a co-maker other
        // than the one currently being viewed only ever changes the tabs strip and Recent
        // Activity, both server-rendered fragments here, never a second GET and never a reload.
        const personSwitchRegion = document.querySelector('[data-person-switch-region]');
        if (personSwitchRegion && typeof payload.person_switch_html === 'string') personSwitchRegion.innerHTML = payload.person_switch_html;
        const recentActivityBody = document.querySelector('[data-recent-activity-body]');
        if (recentActivityBody && typeof payload.recent_activity_html === 'string') recentActivityBody.innerHTML = payload.recent_activity_html;
    } catch (error) {
        showToast(error.message || 'Co-Maker could not be removed. Please retry.', 'error', 3000);
    } finally {
        submit?.removeAttribute('disabled');
    }
});

// Each co-maker tab's ⋮ trigger opens this single, shared, body-level menu instead of an
// in-place dropdown — the tabs row scrolls horizontally (overflow-x-auto), and per CSS an
// element with overflow-x set also clips overflow-y, so any menu positioned relative to a tab
// would get cut off. Positioning it with `fixed` coordinates from getBoundingClientRect(),
// keyed off whichever trigger was clicked, sidesteps that entirely without touching the tabs
// row's own overflow behavior.
let coMakerMenuTrigger = null;

function positionCoMakerActionMenu(menu, trigger) {
    const margin = 8;
    const rect = trigger.getBoundingClientRect();
    const menuWidth = menu.offsetWidth;
    const menuHeight = menu.offsetHeight;

    let left = rect.right - menuWidth;
    if (left < margin) left = rect.left;
    left = Math.min(Math.max(left, margin), window.innerWidth - menuWidth - margin);

    let top = rect.bottom + 6;
    if (top + menuHeight > window.innerHeight - margin) {
        top = rect.top - menuHeight - 6;
    }
    top = Math.max(top, margin);

    menu.style.left = `${left}px`;
    menu.style.top = `${top}px`;
}

function closeCoMakerActionMenu() {
    const menu = document.querySelector('[data-co-maker-action-menu]');
    if (!menu || menu.hidden) return;
    menu.hidden = true;
    coMakerMenuTrigger?.setAttribute('aria-expanded', 'false');
    coMakerMenuTrigger = null;
}

function openCoMakerActionMenu(trigger) {
    const menu = document.querySelector('[data-co-maker-action-menu]');
    if (!menu) return;

    // The menu's Edit/Remove buttons are shared across every co-maker tab, so each open call
    // re-stamps them with the clicked trigger's own record data — never a stale or mixed one.
    const editBtn = menu.querySelector('[data-co-maker-edit-trigger]');
    const removeBtn = menu.querySelector('[data-co-maker-remove-trigger]');
    const { coMakerId, coMakerFullName, coMakerFirstName, coMakerMiddleName, coMakerLastName, coMakerSuffix, coMakerAddress, coMakerDestroyBaseUrl } = trigger.dataset;

    if (editBtn instanceof HTMLElement) {
        editBtn.dataset.coMakerId = coMakerId ?? '';
        editBtn.dataset.coMakerFirstName = coMakerFirstName ?? '';
        editBtn.dataset.coMakerMiddleName = coMakerMiddleName ?? '';
        editBtn.dataset.coMakerLastName = coMakerLastName ?? '';
        editBtn.dataset.coMakerSuffix = coMakerSuffix ?? '';
        editBtn.dataset.coMakerAddress = coMakerAddress ?? '';
    }
    if (removeBtn instanceof HTMLElement) {
        removeBtn.dataset.coMakerId = coMakerId ?? '';
        removeBtn.dataset.coMakerFullName = coMakerFullName ?? '';
        removeBtn.dataset.coMakerDestroyBaseUrl = coMakerDestroyBaseUrl ?? '';
    }

    coMakerMenuTrigger?.setAttribute('aria-expanded', 'false');
    menu.hidden = false;
    positionCoMakerActionMenu(menu, trigger);
    trigger.setAttribute('aria-expanded', 'true');
    coMakerMenuTrigger = trigger;
}

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-co-maker-menu-trigger]');
    if (trigger) {
        if (coMakerMenuTrigger === trigger) {
            closeCoMakerActionMenu();
        } else {
            openCoMakerActionMenu(trigger);
        }
        return;
    }
    // Any other click — outside the menu, or on its own Edit/Remove item — closes it. Edit/Remove
    // already read the data they need synchronously before this handler runs (same click event).
    closeCoMakerActionMenu();
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && coMakerMenuTrigger) closeCoMakerActionMenu();
});

window.addEventListener('scroll', () => closeCoMakerActionMenu(), true);
window.addEventListener('resize', () => closeCoMakerActionMenu());

// Batch Print / Batch Download / Delete Selected on the Business / Income Sources list. Purely
// additive: every existing per-row Edit/Print/Download/Delete action is untouched and keeps
// working exactly as before — this only reads which row checkboxes are checked and mirrors that
// into hidden batch forms (already carrying the folder's current co_maker_id) before submitting
// them. Wrapped in a named, re-invocable function (not a load-once IIFE) because the whole panel
// this queries is one of the two regions AUTO-UPDATE replaces after a successful Add/Update/Delete
// (see refreshBusinessManagePage() below) — a fresh render needs this rewired against its new DOM
// nodes, not left pointing at ones that no longer exist.
window.initBusinessBatchPanel = function initBusinessBatchPanel() {
    const panel = document.querySelector('[data-business-batch-panel]');
    if (!panel) return;

    const selectAll = panel.querySelector('[data-business-select-all]');
    const countLabel = panel.querySelector('[data-business-selected-count]');
    const printButton = panel.querySelector('[data-business-print-selected]');
    const downloadTrigger = panel.querySelector('[data-business-download-selected-trigger]');
    const deleteSelectedButton = panel.querySelector('[data-business-delete-selected]');
    const summaryCount = panel.querySelector('[data-business-selected-summary-count]');
    const summaryEmpty = panel.querySelector('[data-business-selected-empty]');
    const summaryList = panel.querySelector('[data-business-selected-list]');
    const estimateBox = panel.querySelector('[data-business-selected-estimate-box]');
    const estimateText = panel.querySelector('[data-business-selected-estimate]');

    const printForm = document.getElementById('business-batch-print-form');
    const pdfForm = document.getElementById('business-batch-export-pdf-form');
    const excelForm = document.getElementById('business-batch-export-excel-form');

    const rowCheckboxes = () => [...panel.querySelectorAll('[data-business-select]')];
    const uniqueRowIds = () => [...new Set(rowCheckboxes().map((checkbox) => checkbox.value))];
    // Desktop and mobile layouts each render their own checkbox per business id, so a naive
    // filter would double-count a business selected in both. Dedupe by value (first match wins).
    const selectedCheckboxes = () => {
        const seen = new Set();
        return rowCheckboxes().filter((checkbox) => {
            if (!checkbox.checked || seen.has(checkbox.value)) return false;
            seen.add(checkbox.value);
            return true;
        });
    };

    const setDownloadEnabled = (enabled) => {
        if (!downloadTrigger) return;
        downloadTrigger.classList.toggle('opacity-55', !enabled);
        downloadTrigger.classList.toggle('pointer-events-none', !enabled);
        downloadTrigger.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        downloadTrigger.tabIndex = enabled ? 0 : -1;
    };

    const refresh = () => {
        const selected = selectedCheckboxes();
        const total = uniqueRowIds().length;

        if (countLabel) countLabel.textContent = `${selected.length} selected`;
        if (summaryCount) summaryCount.textContent = `${selected.length} report${selected.length === 1 ? '' : 's'} selected`;
        if (selectAll) {
            selectAll.checked = total > 0 && selected.length === total;
            selectAll.indeterminate = selected.length > 0 && selected.length < total;
        }
        if (printButton) printButton.toggleAttribute('disabled', selected.length === 0);
        if (deleteSelectedButton) deleteSelectedButton.toggleAttribute('disabled', selected.length === 0);
        setDownloadEnabled(selected.length > 0);

        if (summaryEmpty) summaryEmpty.hidden = selected.length > 0;
        if (summaryList) {
            summaryList.hidden = selected.length === 0;
            summaryList.innerHTML = '';
            selected.forEach((checkbox, index) => {
                const item = document.createElement('li');
                item.textContent = `${index + 1}. ${checkbox.dataset.businessName ?? ''}`;
                summaryList.appendChild(item);
            });
        }
        if (estimateBox && estimateText) {
            estimateBox.hidden = selected.length === 0;
            estimateText.textContent = selected.length === 0
                ? ''
                : selected.length <= 2
                    ? 'Short reports will be combined onto one printed page when space allows.'
                    : `${selected.length} reports selected — output will span as many pages as the combined content naturally needs.`;
        }
    };

    const syncBatchForm = (form) => {
        if (!form) return;
        form.querySelectorAll('[data-business-batch-id-input]').forEach((input) => input.remove());
        selectedCheckboxes().forEach((checkbox) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'income_source_ids[]';
            input.value = checkbox.value;
            input.dataset.businessBatchIdInput = 'true';
            form.appendChild(input);
        });
    };

    panel.addEventListener('change', (event) => {
        if (event.target.matches('[data-business-select]')) {
            refresh();
        } else if (event.target.matches('[data-business-select-all]')) {
            rowCheckboxes().forEach((checkbox) => { checkbox.checked = event.target.checked; });
            refresh();
        }
    });

    printButton?.addEventListener('click', () => {
        if (selectedCheckboxes().length === 0) return;
        syncBatchForm(printForm);
        printForm?.submit();
    });

    panel.querySelectorAll('[data-business-batch-pdf-submit]').forEach((button) => button.addEventListener('click', () => {
        if (selectedCheckboxes().length === 0) return;
        syncBatchForm(pdfForm);
        pdfForm?.submit();
    }));
    panel.querySelectorAll('[data-business-batch-excel-submit]').forEach((button) => button.addEventListener('click', () => {
        if (selectedCheckboxes().length === 0) return;
        syncBatchForm(excelForm);
        excelForm?.submit();
    }));

    const deleteDialog = document.getElementById('delete-selected-businesses-dialog');
    const deleteDialogBody = deleteDialog?.querySelector('[data-business-delete-selected-body]');
    const deleteDialogError = deleteDialog?.querySelector('[data-business-delete-selected-error]');
    const deleteForm = deleteDialog?.querySelector('[data-business-delete-selected-form]');
    const deleteSubmitButton = deleteDialog?.querySelector('[data-business-delete-selected-submit]');

    deleteSelectedButton?.addEventListener('click', () => {
        const selected = selectedCheckboxes();
        if (selected.length === 0 || !(deleteDialog instanceof HTMLDialogElement)) return;
        syncBatchForm(deleteForm);
        if (deleteDialogBody) deleteDialogBody.textContent = `You are about to permanently delete ${selected.length} selected Business Report${selected.length === 1 ? '' : 's'}. This action cannot be undone. Existing Business Checks will remain unchanged.`;
        if (deleteSubmitButton) deleteSubmitButton.textContent = `Delete ${selected.length} Report${selected.length === 1 ? '' : 's'}`;
        if (deleteDialogError) { deleteDialogError.hidden = true; deleteDialogError.textContent = ''; }
        deleteDialog.showModal();
    });

    refresh();
};

document.addEventListener('DOMContentLoaded', () => window.initBusinessBatchPanel());

// Instant client-side table sorting, shared by the Saved Businesses table and the Residence &
// Business page's Business Checks table: clicking a column's label or its ↑/↓ control reorders
// the existing <tr> nodes in place (no reload, no request), which naturally preserves each row's
// checkbox state and action menus since the same DOM nodes are moved rather than re-rendered.
// Each table scrolls horizontally on narrow viewports (min-w on the <table>, overflow-x-auto on
// its wrapper) instead of collapsing to an alternate layout, so this same logic applies unchanged
// on desktop, tablet, and mobile.
function initSortableTable(table) {
    const tbody = table.querySelector('tbody');
    const headers = [...table.querySelectorAll('[data-sort-th]')];
    let current = { key: null, direction: null };

    // Blank/— values always sort last, in both ascending and descending order, so the multiplier
    // is applied only to the real comparison and never to the blank-placement branches.
    const compareValues = (aRaw, bRaw, type, multiplier) => {
        const aBlank = !aRaw;
        const bBlank = !bRaw;
        if (aBlank && bBlank) return 0;
        if (aBlank) return 1;
        if (bBlank) return -1;

        let cmp;
        if (type === 'date') cmp = new Date(aRaw) - new Date(bRaw);
        else if (type === 'number') cmp = parseFloat(aRaw) - parseFloat(bRaw);
        else cmp = aRaw.localeCompare(bRaw, undefined, { sensitivity: 'base' });
        return cmp * multiplier;
    };

    const setActiveIndicator = (key, direction) => {
        current = { key, direction };
        headers.forEach((th) => {
            th.setAttribute('aria-sort', th.dataset.sortTh === key ? (direction === 'asc' ? 'ascending' : 'descending') : 'none');
        });
        table.querySelectorAll('[data-sort-toggle]').forEach((label) => {
            label.classList.toggle('text-brand-primary', label.dataset.sortKey === key);
        });
        table.querySelectorAll('[data-sort-btn]').forEach((button) => {
            const isActive = button.dataset.sortKey === key && button.dataset.sortDir === direction;
            button.classList.toggle('text-brand-primary', isActive);
            button.classList.toggle('text-text-muted/40', !isActive);
        });
    };

    const applySort = (key, type, direction) => {
        const rowDatasetKey = `sort${key.charAt(0).toUpperCase()}${key.slice(1)}`;
        const rows = [...tbody.querySelectorAll('tr')];
        const multiplier = direction === 'asc' ? 1 : -1;

        rows.sort((a, b) => compareValues(a.dataset[rowDatasetKey], b.dataset[rowDatasetKey], type, multiplier));

        rows.forEach((row) => tbody.appendChild(row));
        setActiveIndicator(key, direction);
    };

    // Delegated on the table (not one listener per button) so a click anywhere inside a button —
    // including on its inner, pointer-events-none icon — always resolves to the button via
    // closest(), regardless of how the buttons were rendered or re-rendered.
    table.addEventListener('click', (event) => {
        const button = event.target.closest('[data-sort-btn]');
        if (button) {
            applySort(button.dataset.sortKey, button.dataset.sortType, button.dataset.sortDir);
            return;
        }

        // Clicking the header label itself (not just the ↑/↓ control) toggles direction: first
        // click on a column sorts ascending, clicking the same column's label again flips to
        // descending, and so on — while the individual chevrons above always force one explicit
        // direction regardless of the current toggle state.
        const label = event.target.closest('[data-sort-toggle]');
        if (!label) return;
        const key = label.dataset.sortKey;
        const direction = current.key === key && current.direction === 'asc' ? 'desc' : 'asc';
        applySort(key, label.dataset.sortType, direction);
    });
}

document.querySelectorAll('[data-business-sort-table], [data-check-sort-table]').forEach(initSortableTable);

// Business / Income Sources: instant client-side search over the Saved Businesses table — matches
// business name or address, reusing the same data-sort-business_name/data-sort-address values
// already rendered on each row for sorting above, so no new data source or backend query is
// introduced. Hides non-matching rows in place (same DOM nodes, so checkbox selection and sort
// order are both preserved) and shows a dedicated "no matches" row rather than an empty table when
// nothing matches. Named and re-invocable (see initBusinessBatchPanel() above) for the same
// AUTO-UPDATE reason: the table this binds to is replaced whenever the Saved Businesses panel body
// is refreshed after a mutation.
window.initBusinessSearch = function initBusinessSearch() {
    const input = document.querySelector('[data-business-search]');
    const table = document.querySelector('[data-business-sort-table]');
    if (!input || !table) return;

    const rows = [...table.querySelectorAll('[data-business-row]')];
    const emptyRow = table.querySelector('[data-business-empty-search]');
    const term = input.value.trim().toLowerCase();

    const applyFilter = () => {
        const term = input.value.trim().toLowerCase();
        let visible = 0;
        rows.forEach((row) => {
            const matches = !term
                || (row.dataset.sortBusiness_name || '').includes(term)
                || (row.dataset.sortAddress || '').includes(term);
            row.hidden = !matches;
            if (matches) visible++;
        });
        if (emptyRow) emptyRow.hidden = !(term && visible === 0);
    };

    input.addEventListener('input', applyFilter);
    // A refresh preserves whatever search term was already typed (re-applying it against the
    // freshly rendered rows) rather than silently clearing it out from under the user.
    if (term) applyFilter();
};

document.addEventListener('DOMContentLoaded', () => window.initBusinessSearch());

// Combined Print Selected / Download Selected on the Residence & Business Report page — same
// hidden-forms + syncBatchForm pattern as the Business/Income Sources batch panel above, but
// pooling two independent checkbox groups (Residence Checks, Business Checks) into one combined
// selection so a batch output can mix records from both tables.
(() => {
    const panel = document.querySelector('[data-check-batch-panel]');
    if (!panel) return;

    const countLabel = panel.querySelector('[data-check-selected-count]');
    const summaryCount = panel.querySelector('[data-check-selected-summary-count]');
    const printButton = panel.querySelector('[data-check-print-selected]');
    const downloadTrigger = panel.querySelector('[data-check-download-selected-trigger]');
    const selectAllButton = panel.querySelector('[data-check-select-all]');
    const clearSelectionButton = panel.querySelector('[data-check-clear-selection]');
    const deleteSelectedButton = panel.querySelector('[data-check-delete-selected]');

    const printForm = document.getElementById('check-batch-print-form');
    const pdfForm = document.getElementById('check-batch-export-pdf-form');
    const docxForm = document.getElementById('check-batch-export-docx-form');
    const deleteForm = document.getElementById('check-batch-delete-form');

    const allCheckboxes = () => [...document.querySelectorAll('[data-residence-check-select], [data-business-check-select]')];
    const selectedResidenceChecks = () => [...document.querySelectorAll('[data-residence-check-select]:checked')];
    const selectedBusinessChecks = () => [...document.querySelectorAll('[data-business-check-select]:checked')];
    const selectedCount = () => selectedResidenceChecks().length + selectedBusinessChecks().length;

    const setDownloadEnabled = (enabled) => {
        if (!downloadTrigger) return;
        downloadTrigger.classList.toggle('opacity-55', !enabled);
        downloadTrigger.classList.toggle('pointer-events-none', !enabled);
        downloadTrigger.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        downloadTrigger.tabIndex = enabled ? 0 : -1;
    };

    const refresh = () => {
        const count = selectedCount();
        if (countLabel) countLabel.textContent = String(count);
        if (summaryCount) summaryCount.textContent = String(count);
        if (printButton) printButton.toggleAttribute('disabled', count === 0);
        if (deleteSelectedButton) deleteSelectedButton.toggleAttribute('disabled', count === 0);
        setDownloadEnabled(count > 0);
    };

    const syncBatchForm = (form) => {
        if (!form) return;
        form.querySelectorAll('[data-check-batch-id-input]').forEach((input) => input.remove());
        selectedResidenceChecks().forEach((checkbox) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'residence_check_ids[]';
            input.value = checkbox.value;
            input.dataset.checkBatchIdInput = 'true';
            form.appendChild(input);
        });
        selectedBusinessChecks().forEach((checkbox) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'business_check_ids[]';
            input.value = checkbox.value;
            input.dataset.checkBatchIdInput = 'true';
            form.appendChild(input);
        });
    };

    // A single row's Print/Download icon reuses the exact same batch routes/forms as the top
    // toolbar — it just seeds the id list with only that one record instead of reading it from
    // checkbox state, so no dedicated single-record route is needed.
    const submitSingle = (form, kind, id) => {
        if (!form) return;
        form.querySelectorAll('[data-check-batch-id-input]').forEach((input) => input.remove());
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = kind === 'residence' ? 'residence_check_ids[]' : 'business_check_ids[]';
        input.value = id;
        input.dataset.checkBatchIdInput = 'true';
        form.appendChild(input);
        form.submit();
    };

    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-residence-check-select], [data-business-check-select]')) refresh();
    });

    selectAllButton?.addEventListener('click', () => {
        allCheckboxes().forEach((checkbox) => { checkbox.checked = true; });
        refresh();
    });
    clearSelectionButton?.addEventListener('click', () => {
        allCheckboxes().forEach((checkbox) => { checkbox.checked = false; });
        refresh();
    });
    deleteSelectedButton?.addEventListener('click', () => {
        const count = selectedCount();
        if (count === 0 || !deleteForm) return;
        if (!window.confirm(`Delete ${count} selected reports? This action cannot be undone.`)) return;
        syncBatchForm(deleteForm);
        deleteSelectedButton.disabled = true;
        deleteForm.submit();
    });

    printButton?.addEventListener('click', () => {
        if (selectedCount() === 0) return;
        syncBatchForm(printForm);
        printForm?.submit();
    });

    panel.querySelectorAll('[data-check-batch-pdf-submit]').forEach((button) => button.addEventListener('click', () => {
        if (selectedCount() === 0) return;
        syncBatchForm(pdfForm);
        pdfForm?.submit();
    }));
    panel.querySelectorAll('[data-check-batch-docx-submit]').forEach((button) => button.addEventListener('click', () => {
        if (selectedCount() === 0) return;
        syncBatchForm(docxForm);
        docxForm?.submit();
    }));

    panel.querySelectorAll('[data-check-row-print]').forEach((button) => button.addEventListener('click', () => {
        submitSingle(printForm, button.dataset.checkKind, button.dataset.checkId);
    }));
    panel.querySelectorAll('[data-check-row-pdf-submit]').forEach((button) => button.addEventListener('click', () => {
        submitSingle(pdfForm, button.dataset.checkKind, button.dataset.checkId);
    }));
    panel.querySelectorAll('[data-check-row-docx-submit]').forEach((button) => button.addEventListener('click', () => {
        submitSingle(docxForm, button.dataset.checkKind, button.dataset.checkId);
    }));

    refresh();
})();

// Business Check form: selecting a saved business auto-fills the read-only Location field from
// that option's own data-location, since Location always reflects whichever business is chosen
// rather than being independently editable. It also swaps the one helper line under the field
// between its default instructional text and the "Business Report available" / "not yet created"
// status (Applicant flow only — the element is absent for Co-Maker) — a single line always, never
// both stacked, to keep the section compact.
document.addEventListener('change', (event) => {
    const select = event.target.closest('[data-business-check-income-source-select]');
    if (!(select instanceof HTMLSelectElement) || !select.closest('[data-business-check-form]')) return;
    const option = select.selectedOptions[0];
    const form = select.closest('[data-business-check-form]');
    const location = option?.dataset.location ?? '';
    const locationField = form.querySelector('[data-business-check-location]');
    if (locationField instanceof HTMLInputElement) locationField.value = location;
    // CI Date is the other value shared with this business's Business Report (start_date, "Start
    // Date of CI") — same pattern as Location above: refreshed to match whichever business is
    // currently selected, editable afterward, and only actually written back on Save.
    const ciDate = option?.dataset.ciDate ?? '';
    const ciDateField = form.querySelector('[data-business-check-ci-date]');
    if (ciDateField instanceof HTMLInputElement && ciDate) ciDateField.value = ciDate;

    const helper = document.querySelector('[data-business-source-helper]');
    if (!helper) return;
    if (helper.dataset.defaultText === undefined) helper.dataset.defaultText = helper.textContent;
    if (!option || !option.value) {
        helper.textContent = helper.dataset.defaultText;
        helper.classList.remove('text-success');
        helper.classList.add('text-text-muted');
        return;
    }
    const complete = option.dataset.reportComplete === '1';
    helper.textContent = complete ? 'Business Report available' : 'Business Report not yet created';
    helper.classList.toggle('text-success', complete);
    helper.classList.toggle('text-text-muted', !complete);
});

// "Add New Business" quick-create: creates the shared IncomeSource/BusinessReport
// shell via a small AJAX endpoint, then appends+selects the new option locally — no page reload,
// and the Business Check form itself is never auto-submitted by this action.
document.querySelector('[data-business-check-add-another]')?.addEventListener('click', () => {
    const confirmed = window.confirm('Only add another business if it is a genuinely separate business or income source. Do you want to continue?');
    if (!confirmed) return;

    const addButton = document.querySelector('[data-business-check-add-new]');
    if (!(addButton instanceof HTMLButtonElement)) return;
    addButton.disabled = false;
    addButton.click();
});

document.querySelector('[data-quick-add-business-confirm]')?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-quick-add-business-confirm]');
    const dialog = button.closest('dialog');
    const nameField = dialog.querySelector('[data-quick-add-business-name]');
    const templateField = dialog.querySelector('[data-quick-add-business-template]');
    const locationField = dialog.querySelector('[data-quick-add-business-location]');
    const errorBox = dialog.querySelector('[data-quick-add-business-error]');
    const showError = (message) => {
        if (!errorBox) return;
        errorBox.textContent = message;
        errorBox.hidden = false;
    };

    const name = nameField?.value.trim() ?? '';
    const templateId = templateField?.value ?? '';
    const location = locationField?.value.trim() ?? '';
    if (!name) { showError('Business Name is required.'); nameField?.focus(); return; }
    if (!templateId) { showError('Business Type / Income Source is required.'); templateField?.focus(); return; }
    if (!location) { showError('Business location is required.'); locationField?.focus(); return; }
    if (errorBox) errorBox.hidden = true;

    const token = document.querySelector('#business-check-form input[name="_token"]')?.value;
    button.disabled = true;
    try {
        const response = await fetch(button.dataset.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token ?? '' },
            body: JSON.stringify({
                co_maker_id: document.querySelector('#business-check-form input[name="co_maker_id"]')?.value || null,
                business_name: name,
                income_source_template_id: templateId,
                location,
            }),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const firstError = payload?.errors ? Object.values(payload.errors)[0]?.[0] : null;
            showError(firstError || payload?.message || 'Unable to add this business. Please try again.');
            return;
        }

        const select = document.querySelector('[data-business-check-income-source-select]');
        if (select instanceof HTMLSelectElement) {
            const option = document.createElement('option');
            option.value = payload.id;
            option.textContent = payload.name;
            // Reflects what the server actually saved as the shared business address (payload.location),
            // not the raw locally-typed value — the two only ever differ if the save itself failed,
            // and this option's data-location is what every later selection re-reads Location from.
            option.dataset.location = payload.location ?? '';
            option.dataset.reportComplete = '0';
            select.appendChild(option);
            select.value = String(payload.id);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        nameField.value = '';
        templateField.value = '';
        if (locationField) locationField.value = '';
        dialog.close();
    } catch {
        showError('Unable to add this business. Please check your connection and try again.');
    } finally {
        button.disabled = false;
    }
});

// Reset the quick-add form's error state (not its fields — Cancel discarding typed values is
// expected either way) whenever the dialog closes, so a stale error doesn't linger next time.
document.querySelector('[data-quick-add-business-dialog]')?.addEventListener('close', (event) => {
    const errorBox = event.target.querySelector('[data-quick-add-business-error]');
    if (errorBox) errorBox.hidden = true;
    const addButton = document.querySelector('[data-business-check-add-new][data-lock-when-existing="true"]');
    if (addButton instanceof HTMLButtonElement) addButton.disabled = true;
});

// Direct multi-file photo upload widget (Residence/Business/Competitor Photos): keeps newly
// chosen files in a JS-managed array + rebuilt DataTransfer so a removed tile actually stops
// submitting (a native <input type="file">'s FileList can't be edited directly), and flags
// removed existing (already-saved) photos into the field's shared removed-ids hidden input
// instead of touching the file input at all. The drag-and-drop zone, live count, and per-file
// Preview link are optional — they only activate when a field's own markup includes them (only
// the Residence Check form does), so this stays a no-op enhancement for every other caller of
// this same generic widget (Business/Competitor Photos) rather than a behavior change for them.
const initializePhotoUploadField = (field) => {
    if (field.dataset.photoUploadReady) return;
    field.dataset.photoUploadReady = 'true';
    const input = field.querySelector('[data-photo-upload-input]');
    const triggers = field.querySelectorAll('[data-photo-upload-trigger]');
    const grid = field.querySelector('[data-photo-upload-grid]');
    const template = field.querySelector('[data-photo-upload-tile-template]');
    const dropzone = field.querySelector('[data-photo-upload-dropzone]');
    const count = field.querySelector('[data-photo-upload-count]');
    if (!(input instanceof HTMLInputElement) || triggers.length === 0 || !grid || !(template instanceof HTMLTemplateElement)) return;

    let files = [];
    let nextFileId = 0;
    field.getStagedPhotoFiles = () => files.map(({ file }) => file);

    const rebuildInputFiles = () => {
        const transfer = new DataTransfer();
        files.forEach(({ file }) => transfer.items.add(file));
        input.files = transfer.files;
    };

    // Only the real photo tiles count toward the total — the dropzone and a trailing "Add More"
    // or count tile are decorative grid cells, not photos, so they're excluded from both this
    // tally and from where a newly added tile gets inserted (always just before that trailing
    // tile, so it stays last regardless of how many photos have been added this session).
    const updateCount = () => {
        if (count) count.textContent = String(grid.querySelectorAll('[data-photo-upload-existing-tile], [data-photo-upload-new-tile]').length);
    };

    field.clearStagedPhotoFiles = () => {
        grid.querySelectorAll('[data-photo-upload-new-tile]').forEach((tile) => {
            if (tile.dataset.photoUploadObjectUrl) URL.revokeObjectURL(tile.dataset.photoUploadObjectUrl);
            tile.remove();
        });
        files = [];
        input.value = '';
        updateCount();
    };

    const addFiles = (fileList) => {
        const trailingTile = grid.querySelector('[data-photo-upload-count-tile], [data-photo-upload-add-more-tile]');
        Array.from(fileList ?? []).forEach((file) => {
            if (!file.type.startsWith('image/')) return;
            const fileId = String(nextFileId++);
            files.push({ id: fileId, file });
            const tile = template.content.firstElementChild.cloneNode(true);
            const img = tile.querySelector('img');
            const objectUrl = URL.createObjectURL(file);
            img.src = objectUrl;
            tile.dataset.photoUploadFileId = fileId;
            tile.dataset.photoUploadObjectUrl = objectUrl;
            const previewLink = tile.querySelector('[data-photo-upload-preview-new]');
            if (previewLink instanceof HTMLAnchorElement) previewLink.href = objectUrl;
            if (trailingTile) grid.insertBefore(tile, trailingTile); else grid.appendChild(tile);
        });
        rebuildInputFiles();
        updateCount();
    };

    triggers.forEach((trigger) => trigger.addEventListener('click', () => input.click()));
    input.addEventListener('change', () => addFiles(input.files));

    if (dropzone) {
        ['dragover', 'dragenter'].forEach((eventName) => dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.add('border-brand-primary', 'bg-brand-soft/40');
        }));
        ['dragleave', 'dragend'].forEach((eventName) => dropzone.addEventListener(eventName, () => {
            dropzone.classList.remove('border-brand-primary', 'bg-brand-soft/40');
        }));
        dropzone.addEventListener('drop', (event) => {
            event.preventDefault();
            dropzone.classList.remove('border-brand-primary', 'bg-brand-soft/40');
            addFiles(event.dataTransfer?.files);
        });
    }

    grid.addEventListener('click', (event) => {
        const removeNew = event.target.closest('[data-photo-upload-remove-new]');
        if (removeNew) {
            const tile = removeNew.closest('[data-photo-upload-new-tile]');
            const fileId = tile?.dataset.photoUploadFileId;
            const index = files.findIndex((entry) => entry.id === fileId);
            if (index !== -1) files.splice(index, 1);
            if (tile?.dataset.photoUploadObjectUrl) URL.revokeObjectURL(tile.dataset.photoUploadObjectUrl);
            tile?.remove();
            rebuildInputFiles();
            updateCount();
            return;
        }

        const removeExisting = event.target.closest('[data-photo-upload-remove-existing]');
        if (removeExisting) {
            const tile = removeExisting.closest('[data-photo-upload-existing-tile]');
            const photoId = tile?.dataset.photoId;
            if (photoId) {
                const removedInput = document.createElement('input');
                removedInput.type = 'hidden';
                removedInput.name = `${field.dataset.photoUploadRemovedName}[]`;
                removedInput.value = photoId;
                field.appendChild(removedInput);
            }
            tile?.remove();
            updateCount();
        }
    });
};

document.querySelectorAll('[data-photo-upload-field]').forEach(initializePhotoUploadField);

// Business Photos "Photo Groups" repeater: each card is its own caption + multi-file
// photo-upload-field (initializePhotoUploadField above); this only ever handles adding/removing
// whole GROUP cards. Removal follows the exact same id/_delete convention as every other repeater
// in this codebase (see removeRepeaterRow) but deliberately skips the generic
// repeaterRemoveDialog confirmation — a group can hold staged-but-unsaved file uploads that
// heuristic can't see, and the deletion itself is already safely deferred until a successful save
// commits (SaveBusinessCheck::syncPhotoGroups), so there's nothing a confirm dialog would protect
// here that isn't already recoverable by just not saving.
document.querySelectorAll('[data-photo-group-repeater]').forEach((repeater) => {
    if (repeater.dataset.photoGroupReady) return;
    repeater.dataset.photoGroupReady = 'true';
    const rows = repeater.querySelector('[data-photo-group-rows]');
    const template = repeater.querySelector('[data-photo-group-template]');
    let nextIndex = rows?.children.length ?? 0;

    rows?.querySelectorAll('[data-photo-upload-field]').forEach(initializePhotoUploadField);

    repeater.querySelector('[data-photo-group-add]')?.addEventListener('click', () => {
        if (!rows || !template) return;
        const row = template.content.firstElementChild?.cloneNode(true);
        if (!row) return;
        [row, ...row.querySelectorAll('*')].forEach((element) => {
            [...element.attributes].forEach((attribute) => {
                if (attribute.value.includes('__INDEX__')) element.setAttribute(attribute.name, attribute.value.replaceAll('__INDEX__', String(nextIndex)));
            });
        });
        // The heading's "Photo Group N" number is display-only text, not a form value the generic
        // attribute-replacement loop above touches — set directly from this row's own position.
        const numberEl = row.querySelector('[data-photo-group-number]');
        if (numberEl) numberEl.textContent = String(nextIndex + 1);
        nextIndex++;
        rows.append(row);
        row.querySelectorAll('[data-photo-upload-field]').forEach(initializePhotoUploadField);
        row.querySelector('textarea')?.focus();
        row.dispatchEvent(new Event('input', { bubbles: true }));
    });

    repeater.addEventListener('click', (event) => {
        const button = event.target.closest('[data-photo-group-remove]');
        if (!button) return;
        const row = button.closest('[data-photo-group-row]');
        if (!row) return;
        const id = row.querySelector('input[name$="[id]"]')?.value;
        if (id) {
            row.querySelector('[data-delete-field]').value = '1';
            row.hidden = true;
        } else {
            row.remove();
        }
        repeater.dispatchEvent(new Event('input', { bubbles: true }));
    });
});

// Residence Check's single-file, replace-in-place Google Map screenshot upload: unlike the
// multi-file photo widget above, there's only ever one file, so a newly chosen/dropped file just
// replaces whatever is already in the native input, and "Remove" clears it while flagging the
// removal to the backend via a hidden field (the server can't otherwise tell "no new file" apart
// from "remove the existing one").
document.querySelectorAll('[data-map-screenshot-field]').forEach((field) => {
    const input = field.querySelector('[data-map-screenshot-input]');
    const removeFlag = field.querySelector('[data-map-screenshot-remove-flag]');
    const dropzone = field.querySelector('[data-map-screenshot-dropzone]');
    const previewWrap = field.querySelector('[data-map-screenshot-preview-wrap]');
    const previewImg = field.querySelector('[data-map-screenshot-preview-img]');
    if (!(input instanceof HTMLInputElement)) return;

    let objectUrl = null;
    const hadSavedPreview = previewWrap ? !previewWrap.hidden : false;
    const savedPreviewSource = previewImg?.getAttribute('src') ?? '';

    field.clearStagedMapScreenshot = () => {
        input.value = '';
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }
        if (previewImg && savedPreviewSource) previewImg.src = savedPreviewSource;
        if (previewWrap) previewWrap.hidden = !hadSavedPreview;
        if (dropzone) dropzone.hidden = hadSavedPreview;
        if (removeFlag) removeFlag.value = '0';
    };

    const setFile = (file) => {
        if (!file || !file.type.startsWith('image/')) return;
        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = URL.createObjectURL(file);
        if (previewImg) previewImg.src = objectUrl;
        if (previewWrap) previewWrap.hidden = false;
        // Once a screenshot is selected/previewed, the compact dropzone steps aside in favor of
        // the preview's own Replace Screenshot button — no need for both at once.
        if (dropzone) dropzone.hidden = true;
        if (removeFlag) removeFlag.value = '0';
    };

    input.addEventListener('change', () => setFile(input.files?.[0]));

    field.querySelectorAll('[data-map-screenshot-replace]').forEach((button) => button.addEventListener('click', () => input.click()));
    field.querySelectorAll('[data-map-screenshot-remove]').forEach((button) => button.addEventListener('click', () => {
        input.value = '';
        if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        if (previewWrap) previewWrap.hidden = true;
        if (dropzone) dropzone.hidden = false;
        if (removeFlag) removeFlag.value = '1';
    }));

    if (dropzone) {
        ['dragover', 'dragenter'].forEach((eventName) => dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.add('border-brand-primary', 'bg-brand-soft/50');
        }));
        ['dragleave', 'dragend'].forEach((eventName) => dropzone.addEventListener(eventName, () => {
            dropzone.classList.remove('border-brand-primary', 'bg-brand-soft/50');
        }));
        dropzone.addEventListener('drop', (event) => {
            event.preventDefault();
            dropzone.classList.remove('border-brand-primary', 'bg-brand-soft/50');
            setFile(event.dataTransfer?.files?.[0]);
        });
    }
});

// Residence Check Save/Update: still submitted via XMLHttpRequest rather than a plain form POST.
// That's what lets the JSON response drive the working parent success toast (see the brbi:check-saved
// postMessage below) instead of a redirect the closing modal would race. There is deliberately no
// upload percentage or progress bar here: only a disabled button + spinner + a status line below it
// (same [data-*-save-status] pattern as Business Check's own form), chosen from the exact same DOM
// state the server validates against. The normal
// multipart-POST-and-redirect flow (SaveResidenceCheckRequest, SaveResidenceCheck — both completely
// unchanged) is preserved as the non-JS fallback: this listener always preventDefault()s and
// resubmits via XHR with an explicit `Accept: application/json` header, which is exactly the same
// signal Laravel's own ValidationException JSON rendering already keys off (Request::expectsJson())
// — ResidenceCheckController::store() only takes a different (JSON, never a redirect) response
// branch when that header is present; without JavaScript the browser's native submission never
// sends it, so the controller falls through to its original redirect behavior untouched.
document.querySelectorAll('[data-residence-check-form]').forEach((form) => {
    // Idempotent-init guard (same convention as initializePhotoUploadField's photoUploadReady,
    // the repeater's repeaterReady, etc.) — belt-and-braces against this whole block ever running
    // twice for the same <form> (e.g. a script re-execution) and attaching a second independent
    // 'submit' listener, which would otherwise double-fire the XHR/upload for a single real submit.
    if (form.dataset.residenceCheckSubmitReady) return;
    form.dataset.residenceCheckSubmitReady = 'true';

    const submitButton = document.querySelector('[data-residence-check-submit]');
    if (!(submitButton instanceof HTMLButtonElement)) return;

    // Same separate status region + generic "Saving…" button label as Business Check's own
    // [data-business-check-form] submit handler — kept in sync with that one intentionally so the
    // two forms feel like the same application module.
    const setButtonBusy = () => {
        submitButton.disabled = true;
        submitButton.setAttribute('aria-busy', 'true');
        submitButton.querySelector('[data-residence-check-submit-icon]')?.classList.add('hidden');
        submitButton.querySelector('[data-residence-check-submit-spinner]')?.classList.remove('hidden');
        const text = submitButton.querySelector('[data-residence-check-submit-text]');
        if (text) text.textContent = 'Saving…';
    };
    const resetButton = () => {
        delete form.dataset.submitting;
        submitButton.disabled = false;
        submitButton.removeAttribute('aria-busy');
        submitButton.querySelector('[data-residence-check-submit-icon]')?.classList.remove('hidden');
        submitButton.querySelector('[data-residence-check-submit-spinner]')?.classList.add('hidden');
        const text = submitButton.querySelector('[data-residence-check-submit-text]');
        if (text) text.textContent = submitButton.dataset.residenceCheckSubmitLabel ?? (text?.textContent || '');
        const status = document.querySelector('[data-residence-check-save-status]');
        status?.classList.add('hidden');
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        // Belt-and-braces alongside the button's own disabled state: a data-attribute guard on the
        // form itself catches a second submit attempt regardless of how it was triggered (a second
        // click before the button visually disables, Enter in a text field, form.requestSubmit()
        // from elsewhere) — none of those depend on the submit *button*'s own state the way a
        // native click on a disabled button already does.
        if (form.dataset.submitting === 'true' || submitButton.disabled) return;
        form.dataset.submitting = 'true';

        const photoInput = form.querySelector('[data-photo-upload-input]');
        const photoField = photoInput?.closest('[data-photo-upload-field]');
        const stagedPhotos = typeof photoField?.getStagedPhotoFiles === 'function'
            ? photoField.getStagedPhotoFiles()
            : Array.from(photoInput?.files ?? []);
        const hasNewPhotos = stagedPhotos.length > 0;
        const mapScreenshotInput = form.querySelector('[data-map-screenshot-input]');
        const hasNewMapScreenshot = mapScreenshotInput instanceof HTMLInputElement && (mapScreenshotInput.files?.length ?? 0) > 0;

        setButtonBusy();

        const status = document.querySelector('[data-residence-check-save-status]');
        const statusText = status?.querySelector('[data-residence-check-save-status-text]');
        const statusHelper = status?.querySelector('[data-residence-check-save-status-helper]');
        if (status && statusText) {
            status.classList.remove('hidden');
            if (hasNewPhotos || hasNewMapScreenshot) {
                statusText.textContent = 'Uploading media to cloud storage…';
                if (statusHelper) statusHelper.hidden = false;
            } else {
                statusText.textContent = 'Saving Residence Check…';
                if (statusHelper) statusHelper.hidden = true;
            }
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.addEventListener('load', () => {
            let payload = null;
            try {
                payload = JSON.parse(xhr.responseText);
            } catch {
                payload = null;
            }

            if (xhr.status === 200 && payload?.result === 'success') {
                // Left disabled/busy deliberately — the modal is about to close via the
                // brbi:check-saved message below, tearing this whole iframe down. The success
                // message itself was already flashed into the session by the controller (same
                // 'status'/'statusType' keys the non-JS redirect path always used) — the reloaded
                // listing page on the other side of that message picks it up and renders the toast
                // itself, so it isn't repeated here.
                if (window.parent !== window) {
                    window.parent.postMessage({ type: 'brbi:check-saved', returnUrl: payload.return_url, message: payload.message, statusType: payload.status_type }, window.location.origin);
                }
                return;
            }

            resetButton();
            if (xhr.status === 200 && payload?.result === 'no_change') {
                showToast(payload.message, 'info');
                return;
            }
            if (xhr.status === 502 && payload?.result === 'cloud_failure') {
                showToast(payload.message, 'error');
                return;
            }
            if (xhr.status === 422 && payload?.errors) {
                const firstMessage = Object.values(payload.errors).flat()[0];
                showToast(firstMessage || 'Please correct the highlighted fields. No changes were saved.', 'error');
                return;
            }
            // Anything else (an unexpected server error, a malformed response) never surfaces its
            // own raw text/exception details — only this generic, safe retry message.
            showToast('Residence Check could not be saved. Please check your connection and try again.', 'error');
        });

        xhr.addEventListener('error', () => {
            resetButton();
            showToast('Residence Check could not be saved. Please check your connection and try again.', 'error');
        });

        const payload = new FormData(form);
        if (photoInput instanceof HTMLInputElement) {
            payload.delete(photoInput.name);
            stagedPhotos.forEach((file) => payload.append(photoInput.name, file, file.name));
        }
        xhr.send(payload);
    });
});

// Live character counter for a textarea, paired with its own maxlength attribute so the
// displayed limit always matches what's actually enforced.
document.querySelectorAll('[data-char-counter-field]').forEach((field) => {
    const input = field.querySelector('[data-char-counter-input]');
    const value = field.querySelector('[data-char-counter-value]');
    if (!(input instanceof HTMLTextAreaElement) || !value) return;
    input.addEventListener('input', () => { value.textContent = String(input.value.length); });
});

document.querySelectorAll('[data-editing-presence]').forEach((node) => {
    const type = node.dataset.editingType;
    const id = node.dataset.editingId;
    const label = node.dataset.editingLabel || 'record';
    const token = node.querySelector('input[name="_token"]')?.value
        || document.querySelector('input[name="_token"]')?.value;
    if (!type || !id || !token) return;

    const banner = node.querySelector('[data-editing-presence-banner]');
    const text = node.querySelector('[data-editing-presence-text]');
    const renderPresence = (otherEditors) => {
        if (!banner || !text) return;
        if (otherEditors && otherEditors.length > 0) {
            const names = otherEditors.map((editor) => editor.name);
            const who = names.length === 1
                ? names[0]
                : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
            const verb = names.length === 1 ? 'is' : 'are';
            banner.hidden = false;
            text.textContent = `${who} ${verb} currently editing this ${label}.`;
        } else {
            banner.hidden = true;
        }
    };

    const ping = async () => {
        try {
            const response = await fetch('/editing-presence/heartbeat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ type, id }),
            });
            if (!response.ok) return;
            renderPresence((await response.json()).other_editors);
        } catch {
            // Best-effort presence signal — a failed heartbeat should never block editing.
        }
    };

    ping();
    const interval = window.setInterval(ping, 30000);

    window.addEventListener('beforeunload', () => {
        window.clearInterval(interval);
        navigator.sendBeacon?.('/editing-presence/release', new Blob([JSON.stringify({ type, id, _token: token })], { type: 'application/json' }));
    }, { once: true });
});

// Header "Scheduled Today" bell: auto-update, not auto-refresh. Polls a small authenticated
// feed endpoint roughly every 30 seconds so a newly created scheduled/due notification (parent,
// Bank target, or Asset target — the server's ScheduledTodayNotificationFeed already resolves
// all three identically) appears without the user pressing refresh. The page itself never
// reloads; only the bell badge and its dropdown panel are swapped, and only from this one
// authoritative response — no matching/counting logic is duplicated here.
(() => {
    const bell = document.querySelector('[data-scheduled-today-bell]');
    const feedUrl = bell instanceof HTMLElement ? bell.dataset.scheduledTodayFeedUrl : undefined;
    const menu = bell instanceof HTMLElement ? bell.closest('[data-context-menu]') : null;
    if (!(bell instanceof HTMLElement) || !feedUrl || !(menu instanceof HTMLElement)) return;

    let requestInFlight = false;

    const applyBadge = (unreadCount) => {
        let badge = bell.querySelector('[data-scheduled-today-count]');
        if (unreadCount > 0) {
            if (!(badge instanceof HTMLElement)) {
                badge = document.createElement('span');
                badge.className = 'absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-danger px-1 text-[0.625rem] font-bold leading-none text-white ring-2 ring-surface';
                badge.setAttribute('data-scheduled-today-count', '');
                bell.append(badge);
            }
            badge.textContent = unreadCount > 9 ? '9+' : String(unreadCount);
        } else if (badge instanceof HTMLElement) {
            badge.remove();
        }
    };

    const applyPanel = (html) => {
        if (typeof html !== 'string' || html === '') return;
        const current = menu.querySelector('[data-scheduled-today-panel]');
        if (!(current instanceof HTMLElement)) return;
        const fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-scheduled-today-panel]');
        // Swapping only this element (never the <details>/<summary> around it) leaves the
        // dropdown's own open/closed state completely untouched either way.
        if (fresh instanceof HTMLElement) current.replaceWith(fresh);
    };

    const checkForUpdates = async () => {
        if (requestInFlight || document.hidden) return;
        requestInFlight = true;
        try {
            const response = await fetch(feedUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) return;
            const payload = await response.json();
            applyBadge(Number(payload.unread_count) || 0);
            applyPanel(payload.html);
        } catch {
            // A temporary network hiccup must stay silent — keep whatever badge/panel is already
            // showing and let the next interval retry.
        } finally {
            requestInFlight = false;
        }
    };

    window.setInterval(checkForUpdates, 30000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) checkForUpdates();
    });
})();

// A required field failing server validation (e.g. Business Check's Location/CI Date) is only
// actually visible if the accordion section containing it happens to already be open — generic
// fix: any <details> holding an aria-invalid field from the just-rendered @error() state is
// forced open, and the first such field gets focus, regardless of which page/section it's in.
document.querySelectorAll('[aria-invalid="true"]').forEach((field) => {
    const details = field.closest('details:not([open])');
    if (details) details.open = true;
});
document.querySelector('[aria-invalid="true"]')?.focus();

// Business Check's required-photo validation error: same temporary toast as Residence Check's own
// (XHR-driven) missing-photo error, surfaced here instead from a marker left by the server-rendered
// redirect (see the [data-business-check-photo-error] span in business-checks/form.blade.php). The
// accordion-open and field-level message already happen server-side/via the aria-invalid handling
// above — this only adds the toast, and only once per page load (one marker, one toast).
document.querySelectorAll('[data-business-check-photo-error]').forEach((node) => {
    showToast(node.dataset.businessCheckPhotoError, 'error');
});

// Business Check Save/Update uses the same response-driven modal flow as Residence Check. Every
// response that keeps this form open resets the submitting guard and button; only a successful
// response asks the parent to close the modal and refresh the listing.
document.querySelectorAll('[data-business-check-form]').forEach((form) => {
    if (form.dataset.businessCheckSubmitReady) return;
    form.dataset.businessCheckSubmitReady = 'true';

    const submitButton = document.querySelector('[data-business-check-submit]');
    if (!(submitButton instanceof HTMLButtonElement)) return;
    const submitText = submitButton.querySelector('[data-business-check-submit-text]');
    const submitLabel = submitText?.textContent ?? 'Save Business Check';

    const setButtonBusy = () => {
        submitButton.disabled = true;
        submitButton.setAttribute('aria-busy', 'true');
        submitButton.querySelector('[data-business-check-submit-icon]')?.classList.add('hidden');
        submitButton.querySelector('[data-business-check-submit-spinner]')?.classList.remove('hidden');
        if (submitText) submitText.textContent = 'Saving…';
    };
    const resetButton = () => {
        delete form.dataset.submitting;
        submitButton.disabled = false;
        submitButton.removeAttribute('aria-busy');
        submitButton.querySelector('[data-business-check-submit-icon]')?.classList.remove('hidden');
        submitButton.querySelector('[data-business-check-submit-spinner]')?.classList.add('hidden');
        if (submitText) submitText.textContent = submitLabel;
        document.querySelector('[data-business-check-save-status]')?.classList.add('hidden');
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (form.dataset.submitting === 'true' || submitButton.disabled) return;
        form.dataset.submitting = 'true';

        const hasNewPhotos = (form.querySelector('input[name="business_photos[]"]')?.files?.length ?? 0) > 0
            || (form.querySelector('input[name="competitor_photos[]"]')?.files?.length ?? 0) > 0
            || [...form.querySelectorAll('input[data-photo-upload-input][name^="photo_groups"]')].some((input) => (input.files?.length ?? 0) > 0);
        const hasNewMapScreenshot = (form.querySelector('input[name="map_screenshot"]')?.files?.length ?? 0) > 0;

        setButtonBusy();

        const status = document.querySelector('[data-business-check-save-status]');
        const statusText = status?.querySelector('[data-business-check-save-status-text]');
        const statusHelper = status?.querySelector('[data-business-check-save-status-helper]');
        if (status && statusText) {
            status.classList.remove('hidden');
            if (hasNewPhotos || hasNewMapScreenshot) {
                statusText.textContent = 'Uploading media to cloud storage…';
                if (statusHelper) statusHelper.hidden = false;
            } else {
                statusText.textContent = 'Saving Business Check…';
                if (statusHelper) statusHelper.hidden = true;
            }
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.addEventListener('load', () => {
            let payload = null;
            try {
                payload = JSON.parse(xhr.responseText);
            } catch {
                payload = null;
            }

            resetButton();
            if (xhr.status === 200 && payload?.result === 'success') {
                if (window.parent !== window) {
                    window.parent.postMessage({ type: 'brbi:check-saved', returnUrl: payload.return_url, message: payload.message, statusType: payload.status_type }, window.location.origin);
                } else {
                    window.location.assign(payload.return_url);
                }
                return;
            }
            if (xhr.status === 200 && payload?.result === 'no_change') {
                showToast(payload.message, 'info');
                return;
            }
            if (xhr.status === 422 && payload?.errors) {
                const firstMessage = Object.values(payload.errors).flat()[0];
                showToast(firstMessage || 'Please correct the highlighted fields. No changes were saved.', 'error');
                return;
            }
            showToast('Business Check could not be saved. Please check your connection and try again.', 'error');
        });

        xhr.addEventListener('error', () => {
            resetButton();
            showToast('Business Check could not be saved. Please check your connection and try again.', 'error');
        });

        xhr.addEventListener('abort', resetButton);
        xhr.send(new FormData(form));
    });
});
