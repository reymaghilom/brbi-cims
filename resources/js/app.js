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
        if (backdrop) backdrop.hidden = false;
        document.body.classList.add('overflow-hidden');
    }
}

function closeFolderPreview(browser) {
    browser?.querySelector('[data-folder-preview-panel]')?.removeAttribute('data-open');
    const backdrop = browser?.querySelector('[data-folder-preview-backdrop]');
    if (backdrop) backdrop.hidden = true;
    document.body.classList.remove('overflow-hidden');
}

function showToast(message, type = 'success', duration = 4500) {
    const region = document.querySelector('[data-toast-region]');
    if (!region) return;
    const toast = document.createElement('div');
    toast.className = `rounded-card border px-4 py-3 text-sm font-semibold shadow-float ${type === 'error' ? 'border-danger/25 bg-danger-soft text-danger' : 'border-success/25 bg-success-soft text-success'}`;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    toast.textContent = message;
    region.append(toast);
    window.setTimeout(() => toast.remove(), duration);
}

function toggleFolderPreviewPanel(browser) {
    const layout = browser?.querySelector('[data-folder-browser-layout]');
    const toggle = browser?.querySelector('[data-folder-preview-toggle]');
    const label = toggle?.querySelector('[data-folder-preview-toggle-label]');
    if (!layout || !toggle) return;

    const collapsed = layout.getAttribute('data-panel-collapsed') === 'true';
    const panelWillBeVisible = collapsed;
    layout.setAttribute('data-panel-collapsed', String(!collapsed));
    toggle.setAttribute('aria-expanded', String(collapsed));
    if (label) label.textContent = panelWillBeVisible ? 'Hide Preview Panel' : 'Show Preview Panel';
    // "visible" icon (eye-off) shows while the panel IS visible — i.e. what clicking the button
    // would currently hide; "hidden" icon (eye) shows once it's collapsed instead. Uses
    // setAttribute/removeAttribute rather than the .hidden IDL property: that property doesn't
    // reliably reflect onto <svg> elements in every browser, so setting it silently no-ops and
    // the icon never actually swaps even though the label text updates correctly.
    const visibleIcon = toggle.querySelector('[data-folder-preview-toggle-icon="visible"]');
    const hiddenIcon = toggle.querySelector('[data-folder-preview-toggle-icon="hidden"]');
    const setIconHidden = (icon, hide) => { if (!icon) return; if (hide) icon.setAttribute('hidden', ''); else icon.removeAttribute('hidden'); };
    setIconHidden(visibleIcon, !panelWillBeVisible);
    setIconHidden(hiddenIcon, panelWillBeVisible);
}

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

    const refreshFolderGrid = (delay = 275) => {
        clearTimeout(liveDebounceTimer);
        liveRequest?.abort();
        liveDebounceTimer = window.setTimeout(async () => {
            const browser = search.closest('[data-folder-browser]');
            if (!browser) return;
            const request = new AbortController();
            liveRequest = request;
            input.setAttribute('aria-busy', 'true');
            try {
                const url = new URL(liveEndpoint, window.location.origin);
                const query = input.value.trim();
                if (query) url.searchParams.set('search', query);
                url.searchParams.set('context', browserContext);
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
                browser.querySelector('[data-folder-browser-layout]')?.replaceWith(nextLayout);
                browser.querySelector('[data-folder-browser-artifacts]')?.replaceWith(nextArtifacts);
                document.body.classList.remove('overflow-hidden');

                const historyUrl = new URL(form.action, window.location.origin);
                if (query) historyUrl.searchParams.set('search', query);
                window.history.replaceState({}, '', historyUrl);
            } catch (error) {
                if (error.name !== 'AbortError') showToast('Client folders could not be refreshed. Please retry.', 'error');
            } finally {
                if (liveRequest === request) input.removeAttribute('aria-busy');
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
    search.closest('[data-folder-browser]')?.addEventListener('folder-browser:refresh', () => refreshFolderGrid(0));
}

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
            dialog?.close();
            showToast(payload.message);
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

document.addEventListener('click', (event) => {
    const activeContextMenu = event.target.closest('[data-context-menu]');
    document.querySelectorAll('details[data-context-menu][open]').forEach((menu) => {
        if (menu !== activeContextMenu) menu.removeAttribute('open');
    });

    const contextMenuItem = event.target.closest('[data-context-menu] [role="menuitem"]');
    if (contextMenuItem) contextMenuItem.closest('details[data-context-menu]')?.removeAttribute('open');

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

    const previewToggle = event.target.closest('[data-folder-preview-toggle]');
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
            dialog.dataset.returnFocus = modalTrigger.id || '';
            dialog.showModal();
            dialog.querySelector('[data-modal-close], [autofocus]')?.focus();
        }
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

document.querySelectorAll('[data-toast]').forEach((toast) => {
    window.setTimeout(() => toast.remove(), 3000);
});

document.addEventListener('dblclick', (event) => {
    const tile = event.target.closest('[data-folder-tile]');
    if (!tile || event.target.closest('a, button, [role="menu"]')) return;
    window.location.assign(tile.dataset.folderOpenUrl);
});

const refreshSavedCibiFolder = (returnUrl, folder = {}) => {
    const currentUrl = new URL(window.location.href);
    if (currentUrl.pathname !== returnUrl.pathname || currentUrl.search !== returnUrl.search) {
        window.location.assign(returnUrl.href);
        return;
    }

    const status = document.querySelector('#open-cibi-report [data-module-status]');
    if (status) {
        status.replaceChildren('Completed');
        status.classList.remove('bg-progress-soft', 'text-progress', 'bg-surface-muted', 'text-text-muted');
        status.classList.add('bg-success-soft', 'text-success');
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

    window.location.reload();
};

window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin || event.data?.type !== 'brbi:cibi-saved') return;

    const returnUrl = new URL(event.data.returnUrl, window.location.href);
    if (returnUrl.origin !== window.location.origin) return;

    const dialog = document.querySelector('[data-cibi-report-dialog][open]');
    if (!dialog) return;
    dialog.dataset.cibiSavedReturnUrl = returnUrl.href;
    dialog.dataset.cibiSavedFolder = JSON.stringify(event.data.folder || {});
});

window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin || event.data?.type !== 'brbi:business-saved') return;

    const returnUrl = new URL(event.data.returnUrl, window.location.href);
    if (returnUrl.origin !== window.location.origin) return;

    const dialog = document.querySelector('[data-business-report-dialog][open]');
    if (!dialog) return;
    dialog.dataset.businessSavedReturnUrl = returnUrl.href;
});

const businessSavedNotify = document.querySelector('[data-business-saved-notify]');
if (businessSavedNotify && window.parent !== window) {
    window.parent.postMessage({
        type: 'brbi:business-saved',
        returnUrl: businessSavedNotify.dataset.businessSavedReturnUrl,
    }, window.location.origin);
}

window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin || event.data?.type !== 'brbi:check-saved') return;

    const returnUrl = new URL(event.data.returnUrl, window.location.href);
    if (returnUrl.origin !== window.location.origin) return;

    const dialog = document.querySelector('[data-check-report-dialog][open]');
    if (!dialog) return;
    dialog.dataset.checkSavedReturnUrl = returnUrl.href;
});

const checkSavedNotify = document.querySelector('[data-check-saved-notify]');
if (checkSavedNotify && window.parent !== window) {
    window.parent.postMessage({
        type: 'brbi:check-saved',
        returnUrl: checkSavedNotify.dataset.checkSavedReturnUrl,
    }, window.location.origin);
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

document.querySelectorAll('[data-unsaved-form]').forEach((form) => {
    let dirty = false;
    form.addEventListener('input', (event) => { if (event.target.form === form) dirty = true; });
    form.addEventListener('submit', () => { dirty = false; });
    form.addEventListener('unsaved-form-reset', () => { dirty = false; });
    window.addEventListener('beforeunload', (event) => {
        if (!dirty) return;
        event.preventDefault();
        event.returnValue = '';
    });
});

document.querySelectorAll('dialog').forEach((dialog) => {
    dialog.addEventListener('close', () => {
        dialog.querySelectorAll('video').forEach((video) => video.pause());
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
            if (currentUrl.pathname === returnUrl.pathname && currentUrl.search === returnUrl.search) {
                window.location.reload();
            } else {
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
        const folder = JSON.parse(dialog.dataset.cibiSavedFolder || '{}');
        delete dialog.dataset.cibiSavedReturnUrl;
        delete dialog.dataset.cibiSavedFolder;
        refreshSavedCibiFolder(returnUrl, folder);
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
        if (otherMenu !== menu) otherMenu.removeAttribute('open');
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
            menu.removeAttribute('open');
            menu.querySelector(':scope > summary')?.focus();
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
            if (!field) return;
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

    const showToast = (message) => {
        const region = document.querySelector('[data-toast-region]');
        if (!region) return;
        const toast = document.createElement('div');
        toast.dataset.toast = '';
        toast.className = 'flex items-start gap-3 rounded-card border border-success/25 bg-success-soft p-4 text-success shadow-card';
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
        window.setTimeout(() => toast.remove(), 2500);
    };

    const updateOverview = (payload) => {
        form.querySelector('[data-cibi-revision]')?.replaceChildren(String(payload.report.revision));
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
                showErrors(payload.errors || {}, payload.message || 'The report could not be saved.');
                return;
            }

            updateOverview(payload);
            showToast(payload.message);
            if (window.parent !== window) {
                window.parent.postMessage({
                    type: 'brbi:cibi-saved',
                    returnUrl: payload.return_url,
                    folder: payload.folder,
                }, window.location.origin);
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
        const { coMakerId, coMakerFirstName, coMakerMiddleName, coMakerLastName, coMakerSuffix } = editTrigger.dataset;
        if (idField) idField.value = coMakerId ?? '';
        form.elements.namedItem('first_name').value = coMakerFirstName ?? '';
        form.elements.namedItem('middle_name').value = coMakerMiddleName ?? '';
        form.elements.namedItem('last_name').value = coMakerLastName ?? '';
        form.elements.namedItem('suffix').value = coMakerSuffix ?? '';
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

    // Editing an existing co-maker only changes that one record's own details, never the set of
    // people this folder has, so it's always safe to patch the header/switcher in place with no
    // reload. Adding one can change that set (the very first co-maker brings the whole
    // Applicant/Co-Maker switcher section into existence), which isn't safely patchable without
    // duplicating that section's markup in JS — so an add is instead followed by a short,
    // automatic reload (not a manual one) once the success toast has had a moment to be seen.
    const isEditing = Boolean(form.querySelector('[data-co-maker-id-field]')?.value);

    form.querySelectorAll('[data-co-maker-error-for]').forEach((error) => {
        error.textContent = '';
        error.hidden = true;
    });
    form.querySelectorAll('[aria-invalid="true"]').forEach((field) => field.removeAttribute('aria-invalid'));

    const submit = document.querySelector('[data-co-maker-submit]');
    submit?.setAttribute('disabled', 'disabled');

    try {
        const response = await fetch(form.action, {
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
        showToast(payload.message, 'success', 3000);

        if (isEditing) {
            const fields = payload.coMaker ?? {};
            const editedId = String(fields.id ?? '');
            document.querySelectorAll(`[data-co-maker-display][data-co-maker-id="${editedId}"]`).forEach((display) => {
                display.querySelector('[data-co-maker-field="full_name"]').textContent = fields.full_name ?? '';
                display.querySelector('[data-co-maker-field="relationship_to_applicant"]').textContent = fields.relationship_to_applicant ?? '';
                display.querySelector('[data-co-maker-field="contact_number"]').textContent = fields.contact_number ?? '';
                display.dataset.coMakerAddress = fields.address ?? '';
            });
            document.querySelectorAll(`[data-co-maker-tab="${editedId}"] [data-co-maker-tab-name]`).forEach((name) => {
                name.textContent = fields.full_name ?? '';
            });
        } else {
            window.setTimeout(() => window.location.reload(), 900);
        }
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

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message || 'Co-Maker could not be removed.');

        form.closest('dialog')?.close();
        showToast(payload.message, 'success', 3000);

        // Removing the currently active co-maker leaves nothing valid for the URL's
        // ?co_maker_id to point at, so drop back to the plain Applicant view; otherwise
        // keep the current view and just refresh the tab/header data behind it.
        const removedId = form.dataset.coMakerRemoveId ?? '';
        const activeId = new URLSearchParams(window.location.search).get('co_maker_id');
        window.setTimeout(() => {
            window.location.href = removedId && removedId === activeId
                ? window.location.pathname
                : window.location.href;
        }, 900);
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
    const { coMakerId, coMakerFullName, coMakerFirstName, coMakerMiddleName, coMakerLastName, coMakerSuffix, coMakerDestroyBaseUrl } = trigger.dataset;

    if (editBtn instanceof HTMLElement) {
        editBtn.dataset.coMakerId = coMakerId ?? '';
        editBtn.dataset.coMakerFirstName = coMakerFirstName ?? '';
        editBtn.dataset.coMakerMiddleName = coMakerMiddleName ?? '';
        editBtn.dataset.coMakerLastName = coMakerLastName ?? '';
        editBtn.dataset.coMakerSuffix = coMakerSuffix ?? '';
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

// Batch Print / Batch Download on the Business / Income Sources list. Purely additive: every
// existing per-row Edit/Print/Download/Delete action is untouched and keeps working exactly as
// before — this only reads which row checkboxes are checked and mirrors that into three hidden
// batch forms (already carrying the folder's current co_maker_id) before submitting them.
(() => {
    const panel = document.querySelector('[data-business-batch-panel]');
    if (!panel) return;

    const selectAll = panel.querySelector('[data-business-select-all]');
    const countLabel = panel.querySelector('[data-business-selected-count]');
    const printButton = panel.querySelector('[data-business-print-selected]');
    const downloadTrigger = panel.querySelector('[data-business-download-selected-trigger]');
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

    refresh();
})();

// Saved Businesses sorting is server-driven (column-header links / the mobile sort select each
// carry a full sort+direction URL) — this just navigates when the mobile <select> changes, since
// a <select> has no native "go to this URL" behavior.
document.addEventListener('change', (event) => {
    const select = event.target.closest('[data-business-sort-select]');
    if (!(select instanceof HTMLSelectElement)) return;
    window.location.assign(select.value);
});

// Combined Print Selected / Download Selected on the Residence & Business Report page — same
// hidden-forms + syncBatchForm pattern as the Business/Income Sources batch panel above, but
// pooling two independent checkbox groups (Residence Checks, Business Checks) into one combined
// selection so a batch output can mix records from both tables.
(() => {
    const panel = document.querySelector('[data-check-batch-panel]');
    if (!panel) return;

    const countLabel = panel.querySelector('[data-check-selected-count]');
    const printButton = panel.querySelector('[data-check-print-selected]');
    const downloadTrigger = panel.querySelector('[data-check-download-selected-trigger]');

    const printForm = document.getElementById('check-batch-print-form');
    const pdfForm = document.getElementById('check-batch-export-pdf-form');
    const docxForm = document.getElementById('check-batch-export-docx-form');

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
        if (countLabel) countLabel.textContent = `${count} selected`;
        if (printButton) printButton.toggleAttribute('disabled', count === 0);
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

    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-residence-check-select], [data-business-check-select]')) refresh();
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

    refresh();
})();

// Business Check form: selecting a saved business auto-fills the read-only Location field from
// that option's own data-location, since Location always reflects whichever business is chosen
// rather than being independently editable.
document.addEventListener('change', (event) => {
    const select = event.target.closest('[data-business-check-income-source-select]');
    if (!(select instanceof HTMLSelectElement) || !select.closest('[data-business-check-form]')) return;
    const location = select.selectedOptions[0]?.dataset.location ?? '';
    const locationField = select.closest('[data-business-check-form]').querySelector('[data-business-check-location]');
    if (locationField instanceof HTMLInputElement) locationField.value = location;
});

// Direct multi-file photo upload widget (Residence/Business/Competitor Photos): keeps newly
// chosen files in a JS-managed array + rebuilt DataTransfer so a removed tile actually stops
// submitting (a native <input type="file">'s FileList can't be edited directly), and flags
// removed existing (already-saved) photos into the field's shared removed-ids hidden input
// instead of touching the file input at all.
document.querySelectorAll('[data-photo-upload-field]').forEach((field) => {
    const input = field.querySelector('[data-photo-upload-input]');
    const trigger = field.querySelector('[data-photo-upload-trigger]');
    const grid = field.querySelector('[data-photo-upload-grid]');
    const template = field.querySelector('[data-photo-upload-tile-template]');
    if (!(input instanceof HTMLInputElement) || !trigger || !grid || !(template instanceof HTMLTemplateElement)) return;

    let files = [];

    const rebuildInputFiles = () => {
        const transfer = new DataTransfer();
        files.forEach((file) => transfer.items.add(file));
        input.files = transfer.files;
    };

    trigger.addEventListener('click', () => input.click());

    input.addEventListener('change', () => {
        Array.from(input.files ?? []).forEach((file) => {
            files.push(file);
            const tile = template.content.firstElementChild.cloneNode(true);
            const img = tile.querySelector('img');
            const reader = new FileReader();
            reader.onload = () => { img.src = String(reader.result); };
            reader.readAsDataURL(file);
            tile.dataset.photoUploadFileName = file.name;
            grid.appendChild(tile);
        });
        rebuildInputFiles();
    });

    grid.addEventListener('click', (event) => {
        const removeNew = event.target.closest('[data-photo-upload-remove-new]');
        if (removeNew) {
            const tile = removeNew.closest('[data-photo-upload-new-tile]');
            const name = tile?.dataset.photoUploadFileName;
            const index = files.findIndex((file) => file.name === name);
            if (index !== -1) files.splice(index, 1);
            tile?.remove();
            rebuildInputFiles();
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
        }
    });
});
