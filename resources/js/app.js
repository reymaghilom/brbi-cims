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

const dashboardActivityModalParams = new URLSearchParams(window.location.search);
const isDashboardActivityModalDocument = window.parent !== window && dashboardActivityModalParams.get('dashboard_modal') === '1';

function dashboardActivityModalForm() {
    if (!isDashboardActivityModalDocument) return null;
    const kind = dashboardActivityModalParams.get('dashboard_kind');
    const targetId = dashboardActivityModalParams.get('dashboard_target_id');

    if (kind === 'default') return document.querySelector('[data-default-check-form]');
    if (kind === 'bank') {
        return targetId
            ? document.querySelector(`[data-bank-target-edit-form="${CSS.escape(targetId)}"]`)
            : document.querySelector('[data-bank-target-form]');
    }
    if (kind === 'asset') {
        return targetId
            ? document.querySelector(`#edit-asset-target-${CSS.escape(targetId)} [data-asset-target-form]`)
            : document.querySelector('[data-asset-target-form]');
    }

    return null;
}

function clearDashboardActivityModalErrors(form) {
    form.querySelectorAll('[data-dashboard-activity-error]').forEach((error) => error.remove());
    form.querySelectorAll('[aria-invalid="true"]').forEach((field) => {
        field.removeAttribute('aria-invalid');
        field.removeAttribute('aria-describedby');
    });
}

function showDashboardActivityModalErrors(form, errors, fallback) {
    clearDashboardActivityModalErrors(form);
    const summary = document.createElement('div');
    summary.dataset.dashboardActivityError = '';
    summary.className = 'mb-4 rounded-control border border-danger/25 bg-danger-soft px-3.5 py-3 text-sm font-semibold text-danger';
    summary.setAttribute('role', 'alert');
    summary.tabIndex = -1;
    summary.textContent = Object.values(errors ?? {}).flat().find((message) => typeof message === 'string') || fallback;
    form.prepend(summary);

    Object.entries(errors ?? {}).forEach(([name, messages], index) => {
        const field = form.querySelector(`[name="${CSS.escape(name)}"]`);
        if (!(field instanceof HTMLElement)) return;
        const message = Array.isArray(messages) ? messages[0] : messages;
        if (typeof message !== 'string') return;
        const id = `dashboard-activity-error-${index}`;
        const error = document.createElement('p');
        error.id = id;
        error.dataset.dashboardActivityError = '';
        error.className = 'mt-2 text-sm font-semibold text-danger';
        error.setAttribute('role', 'alert');
        error.textContent = message;
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', id);
        field.insertAdjacentElement('afterend', error);
    });

    summary.focus();
}

if (isDashboardActivityModalDocument) {
    document.addEventListener('DOMContentLoaded', () => {
        const kind = dashboardActivityModalParams.get('dashboard_kind');
        const targetId = dashboardActivityModalParams.get('dashboard_target_id');
        const targetDialog = kind === 'bank' && targetId
            ? document.getElementById(`edit-bank-target-${targetId}`)
            : (kind === 'asset' && targetId ? document.getElementById(`edit-asset-target-${targetId}`) : null);
        if (targetDialog instanceof HTMLDialogElement) {
            targetDialog.showModal();
            targetDialog.querySelector('input:not([type="hidden"]), select, textarea, button')?.focus();
        }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form !== dashboardActivityModalForm() || event.defaultPrevented) return;
        if (form.matches('[data-no-change-guard]') && !noChangeGuardIsDirty(form)) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        clearDashboardActivityModalErrors(form);
        const submitter = event.submitter instanceof HTMLButtonElement ? event.submitter : form.querySelector('[type="submit"]');
        const originalLabel = submitter?.textContent ?? '';
        if (submitter instanceof HTMLButtonElement) {
            submitter.disabled = true;
            submitter.setAttribute('aria-busy', 'true');
            submitter.textContent = 'Saving…';
        }

        try {
            const response = await fetch(form.action, {
                method: form.method,
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const contentType = response.headers.get('content-type') ?? '';
            const payload = contentType.includes('application/json') ? await response.json().catch(() => ({})) : {};
            if (!response.ok) {
                showDashboardActivityModalErrors(form, payload.errors, payload.message || 'Unable to save this CI Activity. Please try again.');
                return;
            }

            const kind = dashboardActivityModalParams.get('dashboard_kind');
            const status = form.querySelector('[name="status"]')?.value;
            const defaultTitle = document.querySelector('[data-default-check-modal-source] #default-check-title span')?.textContent?.replace(/^Edit\s+/, '') || 'CI Activity';
            const message = kind === 'bank'
                ? 'Bank / Coop Check updated successfully.'
                : (kind === 'asset'
                    ? 'Asset Check updated successfully.'
                    : (status === 'completed' ? `${defaultTitle} marked as completed.` : `${defaultTitle} updated successfully.`));

            window.parent.postMessage({
                type: 'brbi:dashboard-activity-saved',
                activityId: document.querySelector('[data-default-check-activity-id]')?.dataset.defaultCheckActivityId
                    ?? document.querySelector('[data-bank-coop-activity-id]')?.dataset.bankCoopActivityId
                    ?? document.querySelector('[data-asset-activity-id]')?.dataset.assetActivityId,
                targetId: dashboardActivityModalParams.get('dashboard_target_id'),
                message,
            }, window.location.origin);
        } catch {
            showDashboardActivityModalErrors(form, {}, 'Unable to save this CI Activity. Check your connection and try again.');
        } finally {
            if (submitter instanceof HTMLButtonElement && submitter.isConnected) {
                submitter.disabled = false;
                submitter.removeAttribute('aria-busy');
                submitter.textContent = originalLabel;
            }
        }
    });
}

let dashboardRefreshSequence = 0;

async function refreshDashboard() {
    const refreshSequence = ++dashboardRefreshSequence;
    const regions = [...document.querySelectorAll('[data-dashboard-refresh-region]')];
    if (regions.length === 0) return null;
    const response = await fetch(window.location.href, {
        credentials: 'same-origin',
        headers: {
            Accept: 'text/html',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Dashboard-Refresh': '1',
        },
    });
    if (!response.ok) throw new Error('The Dashboard could not be refreshed.');
    const html = await response.text();
    const page = new DOMParser().parseFromString(html, 'text/html');
    if (refreshSequence !== dashboardRefreshSequence) return document.querySelector('[data-work-today-region]');

    regions.forEach((current) => {
        const name = current.dataset.dashboardRefreshRegion;
        const fresh = name ? page.querySelector(`[data-dashboard-refresh-region="${name}"]`) : null;
        if (fresh instanceof HTMLElement) current.innerHTML = fresh.innerHTML;
    });

    const region = document.querySelector('[data-work-today-region]');
    const renderedPage = Number(region?.querySelector('[data-work-today-page]')?.dataset.workTodayPage ?? 1);
    const url = new URL(window.location.href);
    const requestedPage = Math.max(1, Number(url.searchParams.get('work_page') ?? 1));
    if (Number.isInteger(renderedPage) && renderedPage > 0 && renderedPage !== requestedPage) {
        if (renderedPage === 1) url.searchParams.delete('work_page');
        else url.searchParams.set('work_page', String(renderedPage));
        window.history.replaceState(window.history.state, '', url);
    }

    return region;
}

document.querySelectorAll('[data-dashboard-completion-modal]').forEach((dashboardCompletionModal) => {
    if (!(dashboardCompletionModal instanceof HTMLDialogElement)) return;
    const title = dashboardCompletionModal.querySelector('[data-dashboard-completion-title]');
    const target = dashboardCompletionModal.querySelector('[data-dashboard-completion-target]');
    const targetType = dashboardCompletionModal.querySelector('[data-dashboard-completion-target-type]');
    const scheduleBlock = dashboardCompletionModal.querySelector('[data-dashboard-overdue-complete-schedule-block]');
    const scheduleText = dashboardCompletionModal.querySelector('[data-dashboard-overdue-complete-schedule]');
    const remarksBlock = dashboardCompletionModal.querySelector('[data-dashboard-overdue-complete-remarks-block]');
    const remarksText = dashboardCompletionModal.querySelector('[data-dashboard-overdue-complete-remarks]');
    const error = dashboardCompletionModal.querySelector('[data-dashboard-completion-error]');
    const cancel = dashboardCompletionModal.querySelector('[data-dashboard-completion-cancel]');
    const confirm = dashboardCompletionModal.querySelector('[data-dashboard-completion-confirm]');
    const confirmLabel = dashboardCompletionModal.querySelector('[data-dashboard-completion-confirm-label]');
    const readyLabel = confirmLabel?.textContent ?? 'Mark as Completed';
    let activeTrigger = null;

    const reset = () => {
        if (error instanceof HTMLElement) {
            error.hidden = true;
            error.textContent = '';
        }
        if (confirm instanceof HTMLButtonElement) confirm.disabled = false;
        if (confirmLabel instanceof HTMLElement) confirmLabel.textContent = readyLabel;
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest(`[data-modal-open="${dashboardCompletionModal.id}"]`);
        if (!(trigger instanceof HTMLElement)) return;
        activeTrigger = trigger;
        reset();
        if (title instanceof HTMLElement) title.textContent = trigger.dataset.dashboardCompletionName || 'Activity';
        if (target instanceof HTMLElement) target.textContent = trigger.dataset.dashboardCompletionTarget || 'this target';
        if (targetType instanceof HTMLElement) targetType.textContent = trigger.dataset.dashboardCompletionTargetType || 'Target';
        const schedule = trigger.dataset.dashboardCompletionSchedule || '';
        if (scheduleBlock instanceof HTMLElement) scheduleBlock.hidden = schedule === '';
        if (scheduleText instanceof HTMLElement) scheduleText.textContent = schedule;
        const remarks = (trigger.dataset.dashboardCompletionRemarks || '').trim();
        if (remarksBlock instanceof HTMLElement) remarksBlock.hidden = remarks === '';
        if (remarksText instanceof HTMLElement) remarksText.textContent = remarks;
    });

    cancel?.addEventListener('click', () => dashboardCompletionModal.close());
    dashboardCompletionModal.addEventListener('close', () => {
        activeTrigger = null;
        reset();
    });

    confirm?.addEventListener('click', async () => {
        const trigger = activeTrigger;
        if (!(trigger instanceof HTMLElement) || !(confirm instanceof HTMLButtonElement) || !(confirmLabel instanceof HTMLElement)) return;
        const activityName = trigger.dataset.dashboardCompletionName || 'CI Activity';
        const targetName = trigger.dataset.dashboardCompletionTarget || '';
        const formData = new FormData();
        formData.set('_method', trigger.dataset.dashboardCompletionMethod || 'PUT');
        formData.set('_token', document.querySelector('meta[name="csrf-token"]')?.content ?? '');
        formData.set('co_maker_id', trigger.dataset.dashboardCompletionCoMakerId ?? '');
        formData.set('expected_updated_at', trigger.dataset.dashboardCompletionExpectedUpdatedAt ?? '');
        formData.set('status', 'completed');
        formData.set('intent', 'return');
        confirm.disabled = true;
        confirmLabel.textContent = 'Completing…';
        if (error instanceof HTMLElement) error.hidden = true;

        try {
            const response = await fetch(trigger.dataset.dashboardCompletionUpdateUrl ?? '', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(Object.values(payload.errors ?? {}).flat().join(' ') || payload.message || 'Unable to complete this activity.');
            }

            dashboardCompletionModal.close();
            showToast(targetName ? `${activityName} — ${targetName} marked as completed.` : `${activityName} marked as completed.`, 'success');
            refreshDashboard()
                .then((region) => region?.focus())
                .catch(() => showToast('The activity was completed, but the Dashboard could not be refreshed. Refresh the page to see the latest values.', 'error'));
        } catch (requestError) {
            if (error instanceof HTMLElement) {
                error.textContent = requestError instanceof Error ? requestError.message : 'Unable to complete this activity.';
                error.hidden = false;
                error.focus();
            }
            confirm.disabled = false;
            confirmLabel.textContent = readyLabel;
        }
    });
});

window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin || event.data?.type !== 'brbi:dashboard-activity-saved') return;
    const dialog = document.querySelector('[data-dashboard-activity-dialog][open]');
    if (!(dialog instanceof HTMLDialogElement)) return;

    if (event.data.message) showToast(event.data.message, 'success');
    dialog.close();
    const frame = dialog.querySelector('[data-dashboard-activity-frame]');
    window.setTimeout(() => {
        if (frame instanceof HTMLIFrameElement) frame.removeAttribute('src');
    }, 0);
    refreshDashboardWorkToday()
        .then((region) => region?.focus())
        .catch(() => showToast('The activity was saved, but My Work Today could not be refreshed. Refresh the Dashboard to see the latest list.', 'error'));
});

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


/**
 * After a CI / BI or Business Report save is confirmed, the Reports workspace re-reads its own
 * authoritative rows and counts rather than guessing what changed: whether a row is now Completed
 * is decided by the same query that renders the list, never inferred from the Save click. Every
 * active tab, filter, search and sort is carried by the current URL, and a refresh in place adds no
 * history entry. A no-op on every other page. One refresh mechanism, shared by both modals.
 */
function refreshReportsWorkspace() {
    const region = document.querySelector('[data-reports-listing]');
    if (!region) return;

    region.dispatchEvent(new CustomEvent('async-list:load', {
        detail: { url: window.location.href, history: null, withSummary: true },
    }));
}

// AUTO-UPDATE for the Residence & Business Report page's two check tables. Deliberately NOT
// refreshReportsWorkspace(): that one drives the Reports workspace's own [data-reports-listing]
// region (and its unfiltered KPI counts), which this page does not have.
//
// window.location.href is the request URL on purpose — it already carries the exact person context
// this page is displaying (no ?co_maker_id for the Applicant, the exact ?co_maker_id for a
// Co-Maker), so the controller resolves the same ActivePerson it resolved for the page itself and
// the fragment can never come back holding another person's rows. Nothing about the URL, history or
// person context changes; only [data-checks-listing] is replaced.
function refreshChecksListing() {
    const region = document.querySelector('[data-checks-listing]');
    if (!region) return;

    // Subtle in-flight state only (the same [data-refreshing] opacity/pointer-events treatment the
    // Reports and CI Activities listings already use) — the current table stays visible and
    // readable until the response actually lands.
    region.setAttribute('aria-busy', 'true');
    region.setAttribute('data-refreshing', 'true');

    fetch(window.location.href, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } })
        .then((response) => {
            if (!response.ok) throw new Error('The check list could not be updated.');
            return response.text();
        })
        .then((html) => {
            const holder = document.createElement('template');
            holder.innerHTML = html;
            const next = holder.content.querySelector('[data-checks-listing]');
            if (!next) throw new Error('The check list response was incomplete.');
            region.replaceWith(next);

            // Row menus, the row Edit triggers and every per-row <dialog> are already driven by
            // document-level delegated listeners, so they need nothing here. These two are the only
            // narrowly bound pieces inside the region — re-run just them against the new nodes,
            // never a whole-app re-initialization.
            initCheckBatchPanel();
            next.querySelectorAll('[data-check-sort-table]').forEach(initSortableTable);
        })
        .catch(() => {
            // The save itself already succeeded and was already confirmed to the user — only the
            // refresh failed. Say exactly that, leave the page as it is, and never navigate or
            // reload on its behalf.
            showToast('Saved. The list could not be refreshed — reload the page to see the change.', 'error');
        })
        .finally(() => {
            const live = document.querySelector('[data-checks-listing]');
            live?.removeAttribute('aria-busy');
            live?.removeAttribute('data-refreshing');
        });
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

    // Autosuggest sits alongside the live grid filtering below — it never gates it. The grid keeps
    // moving on the same keystrokes whether or not a suggestion is ever picked, so this is pure
    // assistance: it offers the exact accessible client names behind what is already being typed.
    const suggestions = initClientSearchSuggestions(search, input, () => {
        updateClearVisibility();
        refreshFolderGrid(0);
    });

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
        // Both halves of the same keystroke: the grid filters, and the suggestions follow.
        suggestions.refresh();
        refreshFolderGrid();
    });
    clear.addEventListener('click', () => {
        input.value = '';
        updateClearVisibility();
        suggestions.close();
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
        suggestions.close();
        refreshFolderGrid(0, { page: Number(params.get('page')) || 1, history: 'none' });
    });
}

/**
 * The Client Folders client-name autosuggest, presented and operated exactly like the Reports one:
 * an accessible combobox listbox under the input, two characters before it offers anything, its own
 * AbortController so a slower earlier keystroke can never replace a later one, and arrow/Enter/
 * Escape/click-outside handling. It deliberately owns no filtering of its own — `onSelect` hands
 * control straight back to the existing live search, which remains the single authority on what the
 * folder grid shows.
 */
function initClientSearchSuggestions(container, input, onSelect) {
    const list = container.querySelector('[data-client-search-suggestions]');
    const endpoint = container.dataset.suggestUrl;
    const MIN_LENGTH = 2;
    let activeRequest;
    let options = [];
    let activeIndex = -1;

    const close = () => {
        if (!list) return;
        list.hidden = true;
        list.innerHTML = '';
        options = [];
        activeIndex = -1;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    };

    if (!list || !endpoint) return { refresh: () => {}, close: () => {} };

    const setActive = (index) => {
        activeIndex = index;
        options.forEach((option, position) => {
            const selected = position === index;
            option.setAttribute('aria-selected', selected ? 'true' : 'false');
            option.classList.toggle('bg-brand-soft', selected);
            option.classList.toggle('text-brand-primary', selected);
        });
        if (index >= 0) {
            input.setAttribute('aria-activedescendant', options[index].id);
            options[index].scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    };

    const choose = (option) => {
        input.value = option.dataset.name;
        close();
        onSelect();
    };

    // The matched run is emphasised with weight and brand colour rather than a highlighter block,
    // and is built from text nodes so a client name can never inject markup.
    const renderOption = (name, index, term) => {
        const option = document.createElement('li');
        option.id = `folder-search-suggestion-${index}`;
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');
        option.className = 'client-folder-menu-item cursor-pointer';
        option.dataset.name = name;

        const at = name.toLocaleLowerCase().indexOf(term.toLocaleLowerCase());
        if (at < 0 || !term) {
            option.textContent = name;
        } else {
            option.append(document.createTextNode(name.slice(0, at)));
            const match = document.createElement('span');
            match.className = 'font-semibold text-brand-primary';
            match.textContent = name.slice(at, at + term.length);
            option.append(match, document.createTextNode(name.slice(at + term.length)));
        }
        return option;
    };

    const refresh = () => {
        const term = input.value.trim();
        if (term.length < MIN_LENGTH) {
            activeRequest?.abort();
            close();
            return;
        }

        activeRequest?.abort();
        const request = new AbortController();
        activeRequest = request;

        const url = new URL(endpoint, window.location.origin);
        url.searchParams.set('q', term);
        fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: request.signal })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('Suggestions unavailable.'))))
            .then((payload) => {
                // A newer keystroke already superseded this response.
                if (activeRequest !== request) return;
                const names = Array.isArray(payload.suggestions) ? payload.suggestions : [];
                list.innerHTML = '';
                if (names.length === 0) {
                    close();
                    return;
                }
                names.forEach((name, index) => list.append(renderOption(String(name), index, term)));
                options = [...list.querySelectorAll('[role="option"]')];
                list.hidden = false;
                input.setAttribute('aria-expanded', 'true');
                setActive(-1);
            })
            // Suggestions are a convenience: a failure simply leaves the user typing freely, with
            // the folder grid still filtering as normal.
            .catch(() => { if (activeRequest === request) close(); });
    };

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (options.length === 0) return;
            event.preventDefault();
            setActive(event.key === 'ArrowDown'
                ? (activeIndex + 1) % options.length
                : (activeIndex <= 0 ? options.length - 1 : activeIndex - 1));
            return;
        }
        if (event.key === 'Enter' && activeIndex >= 0 && options[activeIndex]) {
            event.preventDefault();
            choose(options[activeIndex]);
        }
        // Tab is deliberately untouched, so focus moves on normally.
    });

    list.addEventListener('mousedown', (event) => {
        const option = event.target.closest('[role="option"]');
        if (!option) return;
        // mousedown, so the click is not lost to the input's own blur.
        event.preventDefault();
        choose(option);
    });

    document.addEventListener('click', (event) => {
        if (!container.contains(event.target)) close();
    });

    return { refresh, close };
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

const FOLDER_EDIT_NO_CHANGES_MESSAGE = 'No changes detected.';
const normalizedFolderEditValue = (value) => value.trim().replace(/\s+/g, ' ').toLocaleUpperCase();
const folderEditHasChanges = (form) => [...form.querySelectorAll('[data-rename-field]')]
    .some((field) => normalizedFolderEditValue(field.value) !== normalizedFolderEditValue(field.defaultValue));
const folderEditNoticeTimeouts = new WeakMap();
const setFolderEditNotice = (form, visible) => {
    const notice = form?.closest('dialog')?.querySelector('[data-folder-edit-notice]');
    if (!notice) return;
    window.clearTimeout(folderEditNoticeTimeouts.get(form));
    notice.hidden = !visible;
    if (visible) {
        folderEditNoticeTimeouts.set(form, window.setTimeout(() => {
            notice.hidden = true;
            folderEditNoticeTimeouts.delete(form);
        }, 4000));
    } else {
        folderEditNoticeTimeouts.delete(form);
    }
};

const clearFolderEditNoticeOnChange = (event) => {
    const field = event.target instanceof Element
        ? event.target.closest('[data-folder-rename-form] [data-rename-field]')
        : null;

    if (field) setFolderEditNotice(field.closest('[data-folder-rename-form]'), false);
};
document.addEventListener('input', clearFolderEditNoticeOnChange);
document.addEventListener('change', clearFolderEditNoticeOnChange);

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-folder-create-form], [data-folder-rename-form], [data-folder-delete-form]');
    if (!form) return;
    event.preventDefault();

    // Keep the rendered values as the comparison baseline. Harmless whitespace/case differences
    // and null/blank optional fields are equivalent, so an unchanged edit never reaches fetch,
    // closes the modal, writes data, creates history, or refreshes the folder fragment.
    if (form.matches('[data-folder-rename-form]') && !folderEditHasChanges(form)) {
        setFolderEditNotice(form, true);
        return;
    }
    if (form.matches('[data-folder-rename-form]')) setFolderEditNotice(form, false);

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
            let firstInvalid;
            form.querySelectorAll('[data-rename-error-for]').forEach((error) => {
                error.textContent = '';
                error.hidden = true;
            });
            form.querySelectorAll('[data-rename-field]').forEach((field) => field.removeAttribute('aria-invalid'));
            Object.entries(payload.errors ?? {}).forEach(([fieldName, messages]) => {
                const field = form.elements.namedItem(fieldName);
                const error = form.querySelector(`[data-rename-error-for="${fieldName}"]`);
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
            if (payload.no_change) {
                setFolderEditNotice(form, true);
                return;
            }
            dialog?.close();
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

// The one inline error line under the Business Template selector carries both of its messages —
// "nothing selected yet" and "this template is already used" — so the CI never sees two competing
// error styles, and never has to look away from the modal to find out why Next did nothing.
const addBusinessTemplateIsDuplicate = (select) => {
    if (!(select instanceof HTMLSelectElement) || !select.value) return false;
    let usedTemplateIds = [];
    try {
        usedTemplateIds = JSON.parse(select.dataset.usedTemplateIds || '[]');
    } catch {
        usedTemplateIds = [];
    }

    return usedTemplateIds.map(String).includes(String(select.value));
};

const showAddBusinessTemplateError = (errorElement, message) => {
    if (!errorElement) return;
    const text = errorElement.querySelector('[data-add-business-template-error-text]');
    if (text) text.textContent = message || text.dataset.defaultMessage || text.textContent;
    errorElement.hidden = false;
};

const hideAddBusinessTemplateError = (errorElement) => {
    if (!errorElement) return;
    errorElement.hidden = true;
    const text = errorElement.querySelector('[data-add-business-template-error-text]');
    // Restored so the next "nothing selected" case never inherits the duplicate wording.
    if (text && text.dataset.defaultMessage) text.textContent = text.dataset.defaultMessage;
};

// Choosing a different template answers the error immediately: a valid one clears it and unblocks
// Next, another already-used one re-states the same reason without waiting for another click.
document.addEventListener('change', (event) => {
    const select = event.target.closest('[data-add-business-template-select]');
    if (!(select instanceof HTMLSelectElement)) return;
    const errorElement = select.closest('dialog')?.querySelector('[data-add-business-template-error]');

    if (addBusinessTemplateIsDuplicate(select)) {
        select.setAttribute('aria-invalid', 'true');
        showAddBusinessTemplateError(errorElement, select.dataset.duplicateTemplateMessage);

        return;
    }

    select.removeAttribute('aria-invalid');
    hideAddBusinessTemplateError(errorElement);
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

    const folderMenuItem = event.target.closest('[data-folder-action-menu] [role="menuitem"]');
    if (folderMenuItem) closeFolderMenus();

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
        closeFolderMenus();
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
            setFolderEditNotice(dialog.querySelector('[data-folder-rename-form]'), false);
            if (dialog.matches('[data-add-business-dialog]') && modalTrigger.dataset.businessTemplateBaseUrl) {
                dialog.dataset.businessReportBaseUrl = modalTrigger.dataset.businessTemplateBaseUrl;
            }
            const cibiFrame = dialog.querySelector('[data-cibi-report-frame]') || dialog.querySelector('[data-business-report-frame]') || dialog.querySelector('[data-check-report-frame]') || dialog.querySelector('[data-dashboard-activity-frame]');
            const cibiLoading = dialog.querySelector('[data-cibi-report-loading]') || dialog.querySelector('[data-business-report-loading]') || dialog.querySelector('[data-check-report-loading]') || dialog.querySelector('[data-dashboard-activity-loading]');
            const cibiUrl = modalTrigger.dataset.cibiReportUrl || modalTrigger.dataset.businessReportUrl || modalTrigger.dataset.checkReportUrl || modalTrigger.dataset.dashboardActivityUrl;
            if (cibiFrame instanceof HTMLIFrameElement && cibiUrl) {
                const requestedUrl = new URL(cibiUrl, window.location.href).href;
                // The CI/BI dialog reloads on EVERY open, like the Business and Check dialogs. Its
            // trigger URL is the same edit route before and after the report exists — only the
            // persisted state behind it changes — so skipping the reload on a matching src is what
            // left a freshly created report reopening into its cached "Save CIBI Report" document
            // instead of the server's "Update CIBI Report". Re-navigating keeps the persisted
            // report authoritative on the FIRST reopen, with no full-page reload.
            const alwaysReload = dialog.matches('[data-cibi-report-dialog]') || dialog.matches('[data-business-report-dialog]') || dialog.matches('[data-check-report-dialog]') || dialog.matches('[data-dashboard-activity-dialog]');
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
            const cibiTitleHeading = dialog.querySelector('[data-cibi-report-title-heading]');
            if (cibiTitleHeading) {
                cibiTitleHeading.textContent = modalTrigger.dataset.modalTitle || cibiTitleHeading.dataset.cibiReportDefaultTitle || cibiTitleHeading.textContent;
            }
            const dashboardActivityTitle = dialog.querySelector('[data-dashboard-activity-title]');
            const dashboardActivityContext = dialog.querySelector('[data-dashboard-activity-context]');
            if (dashboardActivityTitle) dashboardActivityTitle.textContent = modalTrigger.dataset.dashboardActivityTitle || 'CI Activity';
            if (dashboardActivityContext) dashboardActivityContext.textContent = modalTrigger.dataset.dashboardActivityContext || 'Exact activity context';
            dialog.dataset.returnFocus = modalTrigger.id || '';
            // A closed <dialog> is display:none, so it is never in the rendering tree and a
            // loading="lazy" image inside it has nothing to intersect with. Opening the dialog does
            // not reliably start that deferred load, which is what left CI Activity Supporting Proof
            // previews showing an empty frame even though the image route itself answered fine.
            // Promoting them to eager the moment the dialog opens costs nothing (the dialog is being
            // shown anyway) and makes the load deterministic.
            dialog.querySelectorAll('img[loading="lazy"]').forEach((image) => {
                image.loading = 'eager';
            });
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
                showAddBusinessTemplateError(templateError, null);
                templateSelect.focus();
            } else if (reportTrigger) {
                // A standard template this exact person already uses is refused right here, so the
                // encoding form is never opened for a business that cannot be created. Other
                // Business / Source of Income is never in this list: its identity is the set of
                // categories ticked inside the form, so it is judged on Save instead. The server
                // repeats this check in IncomeSourceController::launch() — this is only the fast,
                // friendly half of it.
                let usedTemplateIds = [];
                try {
                    usedTemplateIds = JSON.parse(templateSelect.dataset.usedTemplateIds || '[]');
                } catch {
                    usedTemplateIds = [];
                }
                if (usedTemplateIds.map(String).includes(String(templateSelect.value))) {
                    // Reported inline, in the modal the CI is looking at — never as a toast, which
                    // would take the explanation away from the control that caused it.
                    templateSelect.setAttribute('aria-invalid', 'true');
                    showAddBusinessTemplateError(templateError, templateSelect.dataset.duplicateTemplateMessage);
                    templateSelect.focus();

                    return;
                }

                templateSelect.removeAttribute('aria-invalid');
                hideAddBusinessTemplateError(templateError);
                // The base URL may already carry an active-person query string (?person=co-maker&co_maker_id=…),
                // so the template id has to be appended with the right separator rather than always "?".
                const baseUrl = addBusinessDialog.dataset.businessReportBaseUrl || reportTrigger.dataset.businessReportBaseUrl;
                const separator = baseUrl.includes('?') ? '&' : '?';
                reportTrigger.dataset.businessReportUrl = `${baseUrl}${separator}income_source_template_id=${encodeURIComponent(templateSelect.value)}`;
                addBusinessDialog.close();
                reportTrigger.click();
            }
        }
    }

    const modalClose = event.target.closest('[data-modal-close]');
    if (modalClose) {
        const dialog = modalClose.closest('dialog');
        setFolderEditNotice(dialog?.querySelector('[data-folder-rename-form]'), false);
        dialog?.close();
    }

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
    // handler further down, same pattern as the Business Report dialog above. A dialog opened from
    // a workspace that refreshes itself (the Reports list) opts out: it must never navigate the
    // user away from the list they were working through.
    if (!dialog.matches('[data-cibi-report-stay]')) dialog.dataset.cibiSavedReturnUrl = returnUrl.href;

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

    refreshReportsWorkspace();

    // Everything behind the dialog — the Client Folder's CI/BI module card, folder progress and
    // Recent Activity, or the Reports workspace list — has already been swapped from this same
    // authoritative payload, with no reload and no second GET. So every caller closes here and
    // returns the user to an already-updated page. Reopening then renders the saved report's own
    // state (and therefore its own "Update CIBI Report" wording) server-side; the label is never
    // faked here.
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

    // The template picker is a page-level <dialog>, not part of the panel fragment, so its
    // duplicate-template list is re-stated here from the same authoritative payload. Without it the
    // dataset kept its first-render value for the rest of the session and drifted away from the
    // server after every save and every Business Report delete.
    if (Array.isArray(payload.usedTemplateIds)) {
        document.querySelectorAll('[data-add-business-template-select]').forEach((select) => {
            select.dataset.usedTemplateIds = JSON.stringify(payload.usedTemplateIds);
        });
    }

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
    // A dialog opened from a workspace that refreshes itself (the Reports list) opts out of the
    // close-time navigation below: it must never take the user away from the list they are
    // working through. Everywhere else keeps its existing behaviour exactly.
    if (!dialog.matches('[data-business-report-stay]')) dialog.dataset.businessSavedReturnUrl = returnUrl.href;

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

    refreshReportsWorkspace();

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

    const currentUrl = new URL(window.location.href);

    // Both stay-on-page pages own an authoritative async listing refresh, so a confirmed Check save
    // can use the same stay-on-page pattern as CI/BI and Business Report. The backend-provided
    // canonical message is shown once in the persistent parent, then the modal closes. Error and
    // no-change paths never post this message and therefore can never close the modal or touch a
    // listing — only a confirmed success reaches here at all.
    //
    // Which region to refresh is decided from a stable DOM marker, never from the URL: the page
    // that owns [data-checks-listing] (Residence & Business Report) refreshes that, and the Reports
    // workspace keeps its existing refreshReportsWorkspace() untouched.
    if (dialog.matches('[data-check-report-stay]')) {
        const checksListing = document.querySelector('[data-checks-listing]');
        // Reports' listing spans every folder and person, so a save there always has somewhere to
        // land. The checks listing represents exactly ONE folder + Applicant/Co-Maker, so a save
        // that genuinely returns a DIFFERENT context cannot be shown by refreshing it — that case
        // falls through to the original navigation below, unchanged.
        const sameContext = currentUrl.pathname === returnUrl.pathname && currentUrl.search === returnUrl.search;
        if (!checksListing || sameContext) {
            if (event.data.message) showToast(event.data.message, event.data.statusType || 'success');
            if (checksListing) refreshChecksListing();
            else refreshReportsWorkspace();
            dialog.close();
            return;
        }
    }

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
            delete dialog.dataset.businessReportBaseUrl;
            templateSelect?.removeAttribute('aria-invalid');
            if (templateSelect) templateSelect.value = '';
            hideAddBusinessTemplateError(templateError);
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

// The remove confirmation is ONE shared dialog, so every opener states what it is removing rather
// than inheriting whatever the previous opener left behind. Defaults below are the generic
// repeater wording (Bank accounts, Income sources); IV's Bank/Coop and Loan actions override them.
// Every lookup is optional — the Business Report page reuses this dialog without the extra hooks.
const REPEATER_REMOVE_DEFAULTS = {
    title: 'Remove this entry?',
    message: 'This row already contains information. Are you sure you want to remove it?',
    note: 'The entry will be removed when the CI / BI report is saved.',
    confirmLabel: 'Remove',
    confirmIcon: 'trash',
};

const setRepeaterRemoveDialogContent = (content = {}) => {
    if (!repeaterRemoveDialog) return;
    const { title, message, note, confirmLabel, confirmIcon } = { ...REPEATER_REMOVE_DEFAULTS, ...content };
    document.getElementById(repeaterRemoveDialog.getAttribute('aria-labelledby') || '')?.replaceChildren(title);
    document.getElementById(repeaterRemoveDialog.getAttribute('aria-describedby') || '')?.replaceChildren(message);
    repeaterRemoveDialog.querySelector('[data-repeater-remove-note]')?.replaceChildren(note);
    repeaterRemoveDialog.querySelector('[data-repeater-remove-confirm-label]')?.replaceChildren(confirmLabel);
    repeaterRemoveDialog.querySelectorAll('[data-repeater-remove-confirm-icon]').forEach((icon) => {
        icon.hidden = icon.dataset.repeaterRemoveConfirmIcon !== confirmIcon;
    });
};

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
    const { row, repeater, handler } = pendingRepeaterRemoval;
    pendingRepeaterRemoval = null;
    repeaterRemoveDialog.close();
    (handler || removeRepeaterRow)(row, repeater);
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

    /**
     * Display only, matching CiParticipantService::compactDisplayName on the server: the primary CI
     * and the first companion keep their full names, and every companion after that is shown by
     * first given name alone. The full name stays on data-full-name and in the aria-label, and the
     * submitted contributor_ids are untouched — nothing shortened is ever sent or stored.
     */
    const compactDisplayName = (fullName, position) => {
        const name = (fullName ?? '').trim();

        return position < 2 || name === '' ? name : name.split(' ')[0];
    };

    // Renders "/ Name ×" for each companion, inline after the (always-present, separately
    // markup'd) primary name — e.g. "REY MAGHILOM / ANTHONY YONG × / MARK ×".
    const renderParticipants = (ids) => {
        participantList.innerHTML = '';
        ids.forEach((id, index) => {
            const option = dialog.querySelector(`[data-companion-option][data-user-id="${id}"]`);
            const fullName = option?.dataset.fullName ?? '';
            // index 0 is the first companion, i.e. the second CI on the record overall.
            const displayName = compactDisplayName(fullName, index + 1);
            const item = document.createElement('span');
            item.className = 'flex items-center gap-1';
            item.dataset.companionParticipant = '';
            item.dataset.userId = id;
            const separator = document.createElement('span');
            separator.setAttribute('aria-hidden', 'true');
            separator.textContent = '/';
            const name = document.createElement('span');
            name.dataset.fullName = fullName;
            name.textContent = displayName;
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
    // IV. Summary on Credit / Loan Information keeps data-repeater (post-save id reassignment
    // still walks it) but owns its own grouped add/remove wiring further down this file.
    if (repeater.matches('[data-loan-groups]')) return;
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
            setRepeaterRemoveDialogContent();
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

// CI/BI IV. SUMMARY ON CREDIT / LOAN INFORMATION — one Bank / Coop / Branch group owns ZERO, ONE
// or MANY loan results.
//
// Nothing about the persisted shape changes: a loan result is still exactly one flat
// cibi_loan_records row, and a Bank/Coop with no loan found is a single row carrying only the
// institution plus Performance & Findings (every loan-specific column stays genuinely NULL — no
// "0" / "N/A" filler is ever invented). Grouping therefore lives entirely in the DOM:
//
//   * The institution is edited once, in the group bar. Its input is named for the group's FIRST
//     row so inline validation errors still land on a visible field; one hidden mirror per extra
//     row is regenerated on every structural change, so all rows of a group post the same value.
//   * Indices are renumbered to DOM order after every add/remove, because Laravel reindexes the
//     submitted array to submission (= DOM) order — dense, in-order names keep validation error
//     keys and the post-save sort_order/id mapping pointing at the right row.
//   * Removing the last loan result does NOT remove the institution: the row flips to its empty
//     state (loan cells hidden and cleared, Performance & Findings still editable). Removing the
//     whole Bank/Coop is a separate action and only ever touches that group's own rows.
// CI/BI IV. SUMMARY ON CREDIT / LOAN INFORMATION — a flat, Excel-style table.
//
// One Bank/Coop occupies one row per loan result, and a single row when it has no loan result at
// all. Nothing about the persisted shape changes: a row is still exactly one flat
// cibi_loan_records row, and a zero-result Bank/Coop is a row carrying only the institution plus
// Performance & Findings (every loan column stays genuinely NULL — no "0" / "N/A" filler).
//
//   * Every row owns a real institution input, so whichever row is edited or removed, no row can
//     ever post a blank institution. Only the FIRST row of a consecutive run shows that control —
//     the rest hide it, so the name is printed once per run exactly like the official sheet, and
//     app.js mirrors the visible value onto every sibling in the run.
//   * Indices are renumbered to DOM order after every structural change, because Laravel reindexes
//     the submitted array to submission (= DOM) order — dense, in-order names keep validation error
//     keys and the post-save sort_order/id mapping pointing at the right row.
//   * Removing the last loan result does NOT remove the institution: the row flips to its empty
//     state (loan cells cleared, showing an em dash) with Performance & Findings still editable.
//     Removing the whole Bank/Coop is a separate action and only ever touches its own run.
const cibiLoanSection = document.querySelector('[data-loan-groups]');
const loanRowsIn = (scope) => [...scope.querySelectorAll('[data-repeater-row][data-loan-group]')];
const renumberLoanRecords = () => {
    if (!cibiLoanSection) return;
    const rows = loanRowsIn(cibiLoanSection);
    const runs = new Map();
    rows.forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((field) => {
            field.name = field.name.replace(/^loan_records\[[^\]]*\]/, `loan_records[${index}]`);
        });
        const groupId = row.dataset.loanGroup;
        if (!runs.has(groupId)) runs.set(groupId, []);
        runs.get(groupId).push(row);
    });

    runs.forEach((runRows) => {
        // The institution is printed on the run's first row only; every other row keeps its own
        // input (so it still posts) but hides it, leaving a blank cell like the Excel sheet.
        const visible = runRows.filter((row) => !row.hidden);
        const leader = visible[0] || runRows[0];
        const leaderValue = leader?.querySelector('[data-loan-institution-input]')?.value ?? '';
        runRows.forEach((row) => {
            const controls = row.querySelector('[data-loan-institution-controls]');
            if (controls) controls.hidden = row !== leader;
            const input = row.querySelector('[data-loan-institution-input]');
            if (input && row !== leader) input.value = leaderValue;
        });
    });

    // The table renders no blank starter rows, so it needs its own empty state.
    const emptyState = cibiLoanSection.querySelector('[data-loan-empty-state]');
    if (emptyState) emptyState.hidden = rows.some((row) => !row.hidden);
};

if (cibiLoanSection) {
    const loanRowsFor = (groupId) => loanRowsIn(cibiLoanSection).filter((row) => row.dataset.loanGroup === groupId);

    const setLoanRowEmpty = (row, empty) => {
        row.toggleAttribute('data-loan-empty', empty);
        row.querySelectorAll('[data-loan-detail-controls]').forEach((controls) => { controls.hidden = empty; });
        row.querySelectorAll('[data-loan-detail-blank]').forEach((blank) => { blank.hidden = !empty; });
        const remove = row.querySelector('[data-loan-result-remove]');
        if (remove) remove.hidden = empty;
        // A Bank/Coop with no loan found must persist genuinely blank loan columns — never a
        // stale figure left behind by the result that was just removed.
        if (empty) row.querySelectorAll('[data-loan-detail-controls] input').forEach((field) => { field.value = ''; });
    };

    const cloneLoanRow = (groupId) => {
        const template = cibiLoanSection.querySelector('[data-loan-result-template]');
        const row = template?.content.firstElementChild?.cloneNode(true);
        if (!row) return null;
        row.dataset.loanGroup = groupId;
        return row;
    };

    const addLoanResult = (groupId) => {
        const rows = loanRowsFor(groupId);
        // A zero-result Bank/Coop already has its row — the first loan simply fills it in.
        const emptyRow = rows.find((row) => row.hasAttribute('data-loan-empty'));
        if (emptyRow) {
            setLoanRowEmpty(emptyRow, false);
            renumberLoanRecords();
            emptyRow.querySelector('[data-loan-detail-controls] input')?.focus();
            return;
        }
        const row = cloneLoanRow(groupId);
        if (!row) return;
        rows[rows.length - 1]?.after(row);
        initializeCibiControls(row);
        renumberLoanRecords();
        row.querySelector('[data-loan-detail-controls] input')?.focus();
    };

    const removeLoanResult = (row) => {
        const visible = loanRowsFor(row.dataset.loanGroup).filter((candidate) => !candidate.hidden);
        // The institution itself survives its last loan result — it stays encodable as a
        // zero-result inquiry whose Performance & Findings is still required reading.
        if (visible.length <= 1) setLoanRowEmpty(row, true);
        else removeRepeaterRow(row, cibiLoanSection);
        renumberLoanRecords();
    };

    const removeLoanGroup = (row) => {
        loanRowsFor(row.dataset.loanGroup).forEach((groupRow) => removeRepeaterRow(groupRow, cibiLoanSection));
        renumberLoanRecords();
    };

    cibiLoanSection.querySelector('[data-repeater-add]')?.addEventListener('click', () => {
        const rowsHost = cibiLoanSection.querySelector('[data-repeater-rows]');
        const row = cloneLoanRow(`loan-group-${Date.now()}-${rowsHost?.children.length ?? 0}`);
        if (!row || !rowsHost) return;
        const emptyState = rowsHost.querySelector('[data-loan-empty-state]');
        if (emptyState) emptyState.before(row);
        else rowsHost.append(row);
        // A brand-new Bank/Coop starts as a zero-result inquiry: name it, encode findings, and
        // add loan results only if the inquiry actually turned any up.
        setLoanRowEmpty(row, true);
        initializeCibiControls(row);
        renumberLoanRecords();
        row.querySelector('[data-loan-institution-input]')?.focus();
    });

    cibiLoanSection.addEventListener('input', (event) => {
        const input = event.target.closest('[data-loan-institution-input]');
        if (!input) return;
        const row = input.closest('[data-repeater-row]');
        loanRowsFor(row?.dataset.loanGroup).forEach((sibling) => {
            if (sibling === row) return;
            const mirror = sibling.querySelector('[data-loan-institution-input]');
            if (mirror) mirror.value = input.value;
        });
    });

    cibiLoanSection.addEventListener('click', (event) => {
        const row = event.target.closest('[data-repeater-row]');
        if (!row) return;

        if (event.target.closest('[data-loan-add-result]')) {
            addLoanResult(row.dataset.loanGroup);
            return;
        }

        const resultButton = event.target.closest('[data-loan-result-remove]');
        if (resultButton) {
            if (repeaterRemoveDialog instanceof HTMLDialogElement && repeaterRowHasData(row, cibiLoanSection)) {
                pendingRepeaterRemoval = { row, repeater: cibiLoanSection, handler: removeLoanResult };
                setRepeaterRemoveDialogContent({
                    title: 'Remove Loan?',
                    message: 'This loan contains information. Are you sure you want to remove it?',
                    note: 'This change will be applied when you save the CI / BI report.',
                    confirmLabel: 'Remove Loan',
                    confirmIcon: 'trash',
                });
                repeaterRemoveDialog.showModal();
                repeaterRemoveDialog.querySelector('[data-modal-close]')?.focus();
                return;
            }
            removeLoanResult(row);
            return;
        }

        if (!event.target.closest('[data-loan-group-remove]')) return;
        const groupHasData = loanRowsFor(row.dataset.loanGroup).some((groupRow) => repeaterRowHasData(groupRow, cibiLoanSection));
        if (repeaterRemoveDialog instanceof HTMLDialogElement && groupHasData) {
            pendingRepeaterRemoval = { row, repeater: cibiLoanSection, handler: removeLoanGroup };
            setRepeaterRemoveDialogContent({
                title: 'Remove Bank / Coop?',
                message: 'This entry contains information. Are you sure you want to remove this Bank / Coop and its loan details?',
                note: 'This change will be applied when you save the CI / BI report.',
                confirmLabel: 'Remove Bank / Coop',
                confirmIcon: 'close',
            });
            repeaterRemoveDialog.showModal();
            repeaterRemoveDialog.querySelector('[data-modal-close]')?.focus();
            return;
        }
        removeLoanGroup(row);
    });

    renumberLoanRecords();
}

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
    const residenceFromField = form.querySelector('[data-residence-from-field]');
    const residenceFromLabel = form.querySelector('[data-residence-from-label]');
    const monthlyResidenceField = form.querySelector('[data-monthly-residence-field]');
    const monthlyResidenceLabel = form.querySelector('[data-monthly-residence-label]');
    const parentsHouseField = form.querySelector('[data-parents-house-field]');
    const parentsHouseStatuses = [...form.querySelectorAll('[data-parents-house-status]')];
    const syncResidenceFields = () => {
        const status = residenceStatuses.find((option) => option.checked)?.value;
        const fromApplicable = ['Mortgaged', 'Rented'].includes(status);
        const monthlyApplicable = ['Mortgaged', 'Rented'].includes(status);
        const rented = status === 'Rented';
        // The one optional detail field is named after whichever status is actually selected, so
        // "Mortgaged From" can never be read as a landlord (or the reverse).
        if (residenceFromLabel && fromApplicable) residenceFromLabel.textContent = rented ? 'Rented From' : 'Mortgaged From';
        if (residenceFromField) residenceFromField.hidden = !fromApplicable;
        if (monthlyResidenceField) monthlyResidenceField.hidden = !monthlyApplicable;
        if (monthlyResidenceLabel && monthlyApplicable) monthlyResidenceLabel.textContent = rented ? 'Monthly Rent' : 'Monthly Mortgage Payment';
        // The parents' house status only exists while living with parents; leaving it behind would
        // let a stale secondary choice contradict the present address. The server clears it on
        // save the same way — this just keeps the form honest while the encoder is still working.
        const withParents = status === 'Living with Parents';
        if (parentsHouseField) parentsHouseField.hidden = !withParents;
        if (!withParents) parentsHouseStatuses.forEach((option) => { option.checked = option.value === ''; });
        if (residenceFromDisplay && residenceFromValue) {
            residenceFromDisplay.disabled = !fromApplicable;
            if (!fromApplicable) residenceFromDisplay.value = residenceFromValue.value = '';
            else if (residenceFromDisplay.value === 'N/A') residenceFromDisplay.value = residenceFromValue.value = '';
        }
        if (monthlyRentDisplay && monthlyRentValue) {
            monthlyRentDisplay.disabled = !monthlyApplicable;
            if (!monthlyApplicable) monthlyRentDisplay.value = monthlyRentValue.value = '';
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
            const rows = [...(repeater?.querySelector('[data-repeater-rows]')?.children || [])]
                .filter((element) => element.matches('[data-repeater-row]'));
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
        // The submit button deliberately keeps the wording it opened with for the whole session.
        // A dialog that stays open after saving would otherwise flip "Save CIBI Report" into
        // "Update CIBI Report" under the encoder's cursor the instant the record existed; the
        // server already renders the correct Save/Update wording the next time the form is
        // opened, which is the only moment that state actually changes for the user.
        const totals = {
            checked: payload.report.institutions_checked,
            declared: payload.report.institutions_declared,
            loans: payload.report.loan_records_found,
        };
        renumberLoanRecords();
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
        const { coMakerId, coMakerFirstName, coMakerMiddleName, coMakerLastName, coMakerSuffix } = editTrigger.dataset;
        if (idField) idField.value = coMakerId ?? '';
        form.elements.namedItem('first_name').value = coMakerFirstName ?? '';
        form.elements.namedItem('middle_name').value = coMakerMiddleName ?? '';
        form.elements.namedItem('last_name').value = coMakerLastName ?? '';
        form.elements.namedItem('suffix').value = coMakerSuffix ?? '';
        if (title) title.textContent = 'Edit Co-Maker';
    } else if (title) {
        title.textContent = 'Add Co-Maker';
    }

    // Only the label element is rewritten. Writing the whole button's textContent (as this did)
    // also removed the icon sitting beside it, so the action lost its icon the moment the modal
    // was opened. The co-maker's address is no longer part of this form at all — it stays on the
    // record untouched, since an update that never sends the field cannot blank it.
    const submitLabel = submit?.querySelector('[data-co-maker-submit-label]') ?? submit;
    if (submitLabel) submitLabel.textContent = editTrigger ? 'Update Co-Maker' : 'Save Co-Maker';
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

// Reports and CI Activities reuse the Client Folders pagination experience: an ordinary left-click
// AUTO-UPDATEs just the results region instead of navigating the whole page (which visibly blinked
// the sidebar, header, KPI cards and filter toolbar for a change confined to the list). The same
// contract as [data-folder-pagination] above — same fragment request, same abort/stale-response
// protection, same aria-busy + data-refreshing treatment, same pushState history, and the same
// "leave the current results alone on failure" behaviour. Client Folders keeps its own
// implementation untouched, because its refresh is tied to its live search and preview panel.
//
// The listener sits on the region itself, so links rendered by a previous AUTO-UPDATE keep working
// with no rebinding — the region node persists, only its contents are replaced.
//
// Every modified click is left completely alone (Ctrl/Cmd, Shift, Alt, non-primary button), and a
// disabled arrow rendered as href="#" is ignored rather than fetched.
function initAsyncListRegion(region, linkSelector) {
    let activeRequest;

    const load = (url, historyMode, { withSummary = false } = {}) => {
        activeRequest?.abort();
        const request = new AbortController();
        activeRequest = request;
        region.setAttribute('aria-busy', 'true');
        region.setAttribute('data-refreshing', 'true');

        const headers = { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' };
        // Counts are deliberately unfiltered, so only a mutation can move them — sorting and
        // pagination never pay for recounting them.
        if (withSummary) headers['X-Reports-Summary'] = '1';

        fetch(url, { headers, signal: request.signal })
            .then((response) => {
                if (!response.ok) throw new Error('The list could not be updated.');
                return response.text();
            })
            .then((html) => {
                // A newer click or keystroke already superseded this one — never let a stale
                // response win.
                if (activeRequest !== request) return;
                region.innerHTML = html;

                // When the response carried freshly counted KPI cards, move them out of the region
                // and into the summary row they belong to.
                const summary = region.querySelector('[data-reports-summary-html]');
                if (summary) {
                    const target = document.querySelector('[data-reports-summary]');
                    if (target) target.innerHTML = summary.innerHTML;
                    summary.remove();
                }

                // pushState for a deliberate click or selection, so Back/Forward step through them;
                // replaceState for search-as-you-type, so every keystroke is not a history entry.
                if (historyMode === 'push') window.history.pushState({ asyncListRegion: true }, '', url);
                else if (historyMode === 'replace') window.history.replaceState({ asyncListRegion: true }, '', url);

                region.dispatchEvent(new CustomEvent('async-list:loaded', { detail: { url, history: historyMode } }));
            })
            .catch((error) => {
                // The results the user already has are deliberately left untouched on failure.
                if (error.name !== 'AbortError') {
                    showToast('The list could not be updated. Please retry.', 'error');
                    region.dispatchEvent(new CustomEvent('async-list:error', { detail: { url } }));
                }
            })
            .finally(() => {
                if (activeRequest !== request) return;
                region.removeAttribute('aria-busy');
                region.removeAttribute('data-refreshing');
            });
    };

    // Anything outside the region that needs to drive it (the Reports client search) asks through
    // this one event, so it shares the same request, abort, history and failure handling.
    region.addEventListener('async-list:load', (event) => {
        if (event.detail?.url) load(event.detail.url, event.detail.history ?? 'push', { withSummary: event.detail.withSummary === true });
    });

    region.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest(linkSelector);
        if (!link || !region.contains(link)) return;
        const href = link.getAttribute('href');
        if (!href || href === '#') return;

        event.preventDefault();
        load(link.href, 'push');
    });

    // Back/Forward: re-read the authoritative state from the URL the browser just restored and
    // AUTO-UPDATE to match — no hard reload, and no new history entry for a history move.
    window.addEventListener('popstate', (event) => {
        if (!document.contains(region)) return;
        // Reports must also restore the initial history entry, whose state predates the first
        // pushState call. Other consumers retain their existing state-gated behaviour.
        if (!event.state?.asyncListRegion && !region.matches('[data-reports-listing]')) return;
        load(window.location.href, null);
    });
}

// Reports: sortable headers and pagination both go through the same region. Sorting is server-side
// over the whole result set (see ReportWorkspaceQuery::SORTS) — never a reorder of the rows that
// happen to be on screen — and the links are real URLs, so both still work without JavaScript.
document.querySelectorAll('[data-reports-listing]').forEach((region) => {
    initAsyncListRegion(region, '[data-reports-sort], [data-reports-pagination] a[href]');
});

// Reports status tabs use the same fragment loader as sorting, pagination and live search. Their
// real hrefs remain the no-JavaScript fallback; enhanced clicks keep the shell and current rows in
// place, optimistically update the active tab, and let the shared AbortController enforce
// latest-request-wins when users move quickly between statuses.
document.querySelectorAll('[data-reports-tabs]').forEach((tabs) => {
    const region = document.querySelector('[data-reports-listing]');
    const form = document.querySelector('[data-reports-filters]');
    if (!region) return;

    const validTabs = ['all', 'pending', 'completed'];
    let pendingTab = null;
    const tabFromUrl = (url) => {
        const value = new URL(url, window.location.origin).searchParams.get('tab') ?? 'all';
        return validTabs.includes(value) ? value : 'all';
    };
    // The KPI cards live in their own [data-reports-summary] row, a sibling of this nav — which is
    // exactly why they were never interactive: the click handler below is bound to `tabs`, so a
    // card click never reached it and fell through to a plain full-page navigation that also threw
    // away every other active filter.
    const summaryRow = document.querySelector('[data-reports-summary]');
    // A card's view is its tab AND its date range: Completed This Month is the completed tab with
    // this month's range, plain Completed is the same tab without one. Blade stamps the identical
    // key on each card (see reports/_kpis.blade.php), so the two can never drift apart.
    const viewKey = (url) => {
        const params = new URL(url, window.location.origin).searchParams;
        return [tabFromUrl(url), params.get('from') ?? '', params.get('to') ?? ''].join('|');
    };
    const syncKpiState = (url) => {
        const activeKey = viewKey(url);
        summaryRow?.querySelectorAll('[data-reports-kpi-key]').forEach((card) => {
            if (card.dataset.reportsKpiKey === activeKey) card.setAttribute('aria-current', 'page');
            else card.removeAttribute('aria-current');
        });
    };
    const syncTabState = (url) => {
        const activeTab = tabFromUrl(url);
        syncKpiState(url);
        tabs.querySelectorAll('[data-reports-tab]').forEach((link) => {
            const active = link.dataset.reportsTab === activeTab;
            link.classList.toggle('border-brand-primary', active);
            link.classList.toggle('text-brand-primary', active);
            link.classList.toggle('border-transparent', !active);
            link.classList.toggle('text-text-muted', !active);
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });

        const status = form?.querySelector('[name="tab"]');
        if (status instanceof HTMLSelectElement) status.value = activeTab;
    };
    const syncFilterState = (url) => {
        if (!(form instanceof HTMLFormElement)) return;
        const params = new URL(url, window.location.origin).searchParams;
        ['search', 'client_folder_id', 'report_type', 'person', 'from', 'to'].forEach((name) => {
            const control = form.elements.namedItem(name);
            if (control instanceof HTMLInputElement || control instanceof HTMLSelectElement) {
                control.value = params.get(name) ?? '';
            }
        });
        ['sort', 'direction'].forEach((name) => {
            let control = form.elements.namedItem(name);
            const value = params.get(name);
            if (!value) {
                if (control instanceof HTMLInputElement) control.remove();
                return;
            }
            if (!(control instanceof HTMLInputElement)) {
                control = document.createElement('input');
                control.type = 'hidden';
                control.name = name;
                form.append(control);
            }
            control.value = value;
        });
        syncTabState(url);
    };

    tabs.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('[data-reports-tab]');
        if (!link || !tabs.contains(link)) return;

        event.preventDefault();
        const url = new URL(window.location.href);
        const requestedTab = link.dataset.reportsTab ?? 'all';
        if (pendingTab === requestedTab) return;
        const returningToCurrent = tabFromUrl(url) === requestedTab && !url.searchParams.has('page');
        if (pendingTab === null && returningToCurrent) return;
        url.searchParams.set('tab', requestedTab);
        url.searchParams.delete('page');

        pendingTab = requestedTab;
        syncTabState(url);
        region.dispatchEvent(new CustomEvent('async-list:load', {
            detail: { url: url.toString(), history: returningToCurrent ? null : 'push' },
        }));
    });

    // Delegated on the summary row itself, not on the cards: a save replaces that row's innerHTML
    // with freshly counted cards, and a listener on the container survives that swap.
    summaryRow?.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const card = event.target.closest('[data-reports-kpi]');
        if (!card || !summaryRow.contains(card)) return;

        event.preventDefault();
        // The card's own href is already authoritative — Blade built it from the same filters the
        // tab links carry — so it is reused rather than rebuilt here. Only the page is dropped.
        const url = new URL(card.href, window.location.origin);
        url.searchParams.delete('page');
        const requested = url.toString();
        if (pendingTab === requested) return;
        const current = new URL(window.location.href);
        const returningToCurrent = viewKey(url) === viewKey(current) && !current.searchParams.has('page');
        if (pendingTab === null && returningToCurrent) return;

        pendingTab = requested;
        syncTabState(url);
        region.dispatchEvent(new CustomEvent('async-list:load', {
            detail: { url: requested, history: returningToCurrent ? null : 'push' },
        }));
    });

    region.addEventListener('async-list:loaded', (event) => {
        pendingTab = null;
        syncFilterState(event.detail?.url ?? window.location.href);
    });
    region.addEventListener('async-list:error', () => {
        pendingTab = null;
        syncTabState(window.location.href);
    });
    window.addEventListener('popstate', () => syncFilterState(window.location.href));
});

// Reports filters share the same async listing path as tabs, sorting, pagination and client-name
// search. Each change rebuilds the URL from the whole form, so changing one restriction preserves
// every other active restriction and resets only pagination. The ordinary GET submit and real
// Clear links remain as accessible no-JavaScript fallbacks.
document.querySelectorAll('[data-reports-filters]').forEach((form) => {
    const region = document.querySelector('[data-reports-listing]');
    if (!(form instanceof HTMLFormElement) || !region) return;

    const dateRange = form.querySelector('[data-reports-date-range]');
    const dateSummary = dateRange?.querySelector('summary');
    const dateLabel = dateSummary?.querySelector('span');
    const clearFilters = form.querySelector('[data-reports-clear-filters]');

    const buildUrl = () => {
        const url = new URL(form.action, window.location.origin);
        new FormData(form).forEach((value, key) => {
            if (typeof value === 'string' && value !== '') url.searchParams.set(key, value);
        });
        url.searchParams.delete('page');
        return url;
    };
    const validDateRange = () => {
        const from = form.elements.namedItem('from');
        const to = form.elements.namedItem('to');
        if (!(from instanceof HTMLInputElement) || !(to instanceof HTMLInputElement)) return true;
        if (!from.checkValidity() || !to.checkValidity()) return false;
        return !from.value || !to.value || from.value <= to.value;
    };
    const displayDate = (value, includeYear = true) => {
        if (!value) return '';
        return new Date(`${value}T00:00:00`).toLocaleDateString('en-US', {
            month: 'short', day: 'numeric', ...(includeYear ? { year: 'numeric' } : {}),
        });
    };
    const syncFilterChrome = (url) => {
        const params = new URL(url, window.location.origin).searchParams;
        ['search', 'client_folder_id', 'report_type', 'person', 'tab', 'from', 'to'].forEach((name) => {
            const control = form.elements.namedItem(name);
            if (control instanceof HTMLInputElement || control instanceof HTMLSelectElement) {
                control.value = params.get(name) ?? (name === 'tab' ? 'all' : '');
            }
        });

        const from = params.get('from') ?? '';
        const to = params.get('to') ?? '';
        let label = 'Select Date Range';
        if (from && to) {
            label = `${displayDate(from, from.slice(0, 4) !== to.slice(0, 4))} – ${displayDate(to)}`;
        } else if (from) label = `From ${displayDate(from)}`;
        else if (to) label = `Until ${displayDate(to)}`;
        if (dateLabel) dateLabel.textContent = label;
        if (dateSummary) {
            dateSummary.title = label;
            dateSummary.setAttribute('aria-label', `Date range: ${label}`);
            dateSummary.classList.toggle('border-brand-primary', Boolean(from || to));
            dateSummary.classList.toggle('text-brand-primary', Boolean(from || to));
        }

        if (clearFilters instanceof HTMLAnchorElement) {
            const reset = new URL(form.action, window.location.origin);
            clearFilters.href = reset.toString();
        }
    };
    const refresh = (url = buildUrl()) => {
        region.dispatchEvent(new CustomEvent('async-list:load', {
            detail: { url: url.toString(), history: 'push' },
        }));
    };

    form.addEventListener('change', (event) => {
        if (!(event.target instanceof Element) || !event.target.matches('[data-reports-auto-filter]')) return;
        if (!validDateRange()) return;
        const url = buildUrl();
        syncFilterChrome(url);
        refresh(url);
    });

    form.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a') : null;
        if (!(link instanceof HTMLAnchorElement) || link !== clearFilters) return;
        event.preventDefault();
        const url = new URL(link.href);
        syncFilterChrome(url);
        refresh(url);
    });

    region.addEventListener('async-list:loaded', (event) => syncFilterChrome(event.detail?.url ?? window.location.href));
    region.addEventListener('async-list:error', () => syncFilterChrome(window.location.href));
    syncFilterChrome(window.location.href);
});

document.querySelectorAll('[data-ci-activities-listing]').forEach((region) => {
    initAsyncListRegion(region, '[data-ci-activities-pagination] a[href]');
});

document.querySelectorAll('[data-work-today-region]').forEach((region) => {
    initAsyncListRegion(region, '[data-work-today-pagination] a[href]');
});

// Reports and Global CI Activities client-name search: one accessible combobox implementation over
// the authorized client list. It borrows
// the Client Folders live-search contract — a short debounce, one in-flight request at a time with
// AbortController so a slower earlier keystroke can never overwrite a later one, and an
// AUTO-UPDATE of just the results region instead of a page navigation. The input stays an ordinary
// GET field, so with JavaScript unavailable the same search still works by submitting the form.
function initReportsClientSearch(container) {
    const isCiActivities = container.matches('[data-ci-client-search]');
    const input = container.querySelector('[data-reports-client-input], [data-ci-client-input]');
    const hiddenId = container.querySelector('[data-reports-client-id]');
    const list = container.querySelector('[data-reports-client-suggestions], [data-ci-client-suggestions]');
    const endpoint = container.dataset.suggestUrl;
    const form = input?.closest('form');
    if (!input || !list || !endpoint || !form) return;

    // Suggestions need two characters to be worth offering (the endpoint enforces the same floor),
    // but the table itself filters from the first character — exactly like the Client Folders live
    // search, which also uses this debounce.
    const SUGGEST_MIN_LENGTH = 2;
    const DEBOUNCE_MS = 275;
    let debounceTimer;
    let activeRequest;
    let options = [];
    let activeIndex = -1;

    const close = () => {
        list.hidden = true;
        list.innerHTML = '';
        options = [];
        activeIndex = -1;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    };

    const setActive = (index) => {
        activeIndex = index;
        options.forEach((option, position) => {
            const selected = position === index;
            option.setAttribute('aria-selected', selected ? 'true' : 'false');
            option.classList.toggle('bg-brand-soft', selected);
            option.classList.toggle('text-brand-primary', selected);
        });
        if (index >= 0) {
            input.setAttribute('aria-activedescendant', options[index].id);
            options[index].scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    };

    // Results AUTO-UPDATE through the same region contract pagination and sorting already use, so
    // the Client Name field updates without another action. `page` is never carried, so a changed
    // search always starts at the first page; every other filter, the tab and the active sort come
    // straight off the form and are preserved untouched.
    const runSearch = (historyMode) => {
        const region = document.querySelector(isCiActivities ? '[data-ci-activities-listing]' : '[data-reports-listing]');
        const url = new URL(form.getAttribute('action'), window.location.origin);
        new FormData(form).forEach((value, key) => {
            if (typeof value === 'string' && value !== '') url.searchParams.set(key, value);
        });
        if (!region) {
            window.location.assign(url.toString());
            return;
        }
        region.dispatchEvent(new CustomEvent('async-list:load', { detail: { url: url.toString(), history: historyMode } }));
    };

    const choose = (option) => {
        input.value = option.dataset.name;
        if (hiddenId) hiddenId.value = option.dataset.id;
        close();
        // A deliberate selection is worth a history entry; typing is not.
        runSearch('push');
    };

    // The matched run is emphasised with weight and brand colour rather than a highlighter block,
    // and is built from text nodes so a client name can never inject markup.
    const renderOption = (suggestion, index, term) => {
        const option = document.createElement('li');
        option.id = `${isCiActivities ? 'global-ci' : 'reports'}-client-suggestion-${index}`;
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');
        option.className = 'client-folder-menu-item cursor-pointer';
        option.dataset.id = String(suggestion.id);
        option.dataset.name = suggestion.name;

        const name = suggestion.name ?? '';
        const at = name.toLocaleLowerCase().indexOf(term.toLocaleLowerCase());
        if (at < 0 || !term) {
            option.textContent = name;
        } else {
            option.append(document.createTextNode(name.slice(0, at)));
            const match = document.createElement('span');
            match.className = 'font-semibold text-brand-primary';
            match.textContent = name.slice(at, at + term.length);
            option.append(match, document.createTextNode(name.slice(at + term.length)));
        }
        return option;
    };

    const suggest = () => {
        const term = input.value.trim();
        if (term.length < SUGGEST_MIN_LENGTH) {
            activeRequest?.abort();
            close();
            return;
        }

        activeRequest?.abort();
        const request = new AbortController();
        activeRequest = request;

        const url = new URL(endpoint, window.location.origin);
        url.searchParams.set('q', term);
        fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: request.signal })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('Suggestions unavailable.'))))
            .then((payload) => {
                // A newer keystroke already superseded this response.
                if (activeRequest !== request) return;
                const suggestions = Array.isArray(payload.suggestions) ? payload.suggestions : [];
                list.innerHTML = '';
                if (suggestions.length === 0) {
                    close();
                    return;
                }
                suggestions.forEach((suggestion, index) => list.append(renderOption(suggestion, index, term)));
                options = [...list.querySelectorAll('[role="option"]')];
                list.hidden = false;
                input.setAttribute('aria-expanded', 'true');
                setActive(-1);
            })
            // Suggestions are a convenience: a failure simply leaves the user typing freely.
            .catch(() => { if (activeRequest === request) close(); });
    };

    // One debounced pass drives both halves of the same typed query: the suggestion list and the
    // results themselves. The user never has to pick a suggestion for
    // the table to follow along.
    input.addEventListener('input', () => {
        // Typing again means the pinned exact client no longer applies.
        if (hiddenId) hiddenId.value = '';
        if (input.value.trim().length < SUGGEST_MIN_LENGTH) {
            activeRequest?.abort();
            close();
        }
        clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(() => {
            suggest();
            runSearch('replace');
        }, DEBOUNCE_MS);
    });

    // A cleared field (including the native search "x") drops the client filter and restores the
    // rest of the current view straight away, with no debounce to wait through.
    input.addEventListener('search', () => {
        if (input.value.trim() !== '') return;
        clearTimeout(debounceTimer);
        if (hiddenId) hiddenId.value = '';
        close();
        runSearch('replace');
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (options.length === 0) return;
            event.preventDefault();
            const next = event.key === 'ArrowDown'
                ? (activeIndex + 1) % options.length
                : (activeIndex <= 0 ? options.length - 1 : activeIndex - 1);
            setActive(next);
            return;
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            clearTimeout(debounceTimer);
            if (activeIndex >= 0 && options[activeIndex]) {
                choose(options[activeIndex]);
                return;
            }
            // Free typing without picking a suggestion still searches — by client name only.
            close();
            runSearch('push');
        }
        // Tab is deliberately untouched, so focus moves on normally.
    });

    list.addEventListener('mousedown', (event) => {
        const option = event.target.closest('[role="option"]');
        if (!option) return;
        // mousedown, so the click is not lost to the input's own blur.
        event.preventDefault();
        choose(option);
    });

    document.addEventListener('click', (event) => {
        if (!container.contains(event.target)) close();
    });
}

document.querySelectorAll('[data-reports-client-search], [data-ci-client-search]').forEach(initReportsClientSearch);

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
//
// A named initializer rather than a bare IIFE because the panel lives inside [data-checks-listing],
// which refreshChecksListing() replaces wholesale after a confirmed Residence/Business Check save.
// Every binding below is made against elements found at call time, so re-running this against the
// freshly rendered panel is what keeps Select All, the batch submits and the per-row
// Preview/PDF/Word actions working on the new rows. The old panel's listeners die with the nodes
// they were attached to, so nothing accumulates.
let refreshCheckBatchSelection = () => {};

function initCheckBatchPanel() {
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

    // Published for the document-level 'change' delegate registered once below — registering that
    // listener in here instead would add a fresh duplicate on every fragment refresh.
    refreshCheckBatchSelection = refresh;

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

    // A freshly rendered panel always starts with nothing checked, so this is also what leaves the
    // count at 0 and the Print/Download/Delete Selected controls disabled after a refresh — never a
    // stale count sitting above rows that no longer exist.
    refresh();
}

document.addEventListener('change', (event) => {
    if (event.target.matches('[data-residence-check-select], [data-business-check-select]')) refreshCheckBatchSelection();
});

initCheckBatchPanel();

// Business Check form: the business selector is a REFERENCE only — Business Check never creates
// or edits a Business / Income Source. Selecting an existing business prefills that exact
// business's own Business Name, Location and CI Date (Business Report -> Business Check, one way
// only) and locks whichever of the three that business actually has a value for. Nothing is ever
// created or written back on the Business / Income Sources side.
//
// One deliberately simple rule governs a selection change, with no cross-business bookkeeping:
//
//   RESET all three fields, then populate from the newly selected business alone.
//
// So nothing survives a switch — not a value prefilled from the previous business, and not one the
// CI typed while that previous business was selected. Both belonged to that business's in-progress
// Business Check context, and neither is Business B's data. Clearing the selection back to manual
// mode empties all three the same way.
//
// The guard below is what keeps ordinary typing safe: the reset runs only when the selected
// income_source_id genuinely CHANGES. While the CI stays on one business, whatever they type into
// an unlocked field (an address for a business whose Business Report has none) is left alone.
document.addEventListener('change', (event) => {
    const select = event.target.closest('[data-business-check-income-source-select]');
    if (!(select instanceof HTMLSelectElement) || !select.closest('[data-business-check-form]')) return;

    const selectedId = select.value ?? '';
    if (select.dataset.appliedIncomeSourceId === selectedId) return;
    select.dataset.appliedIncomeSourceId = selectedId;

    const option = select.selectedOptions[0];
    const form = select.closest('[data-business-check-form]');
    const linked = Boolean(selectedId);

    const apply = (selector, value) => {
        const field = form.querySelector(selector);
        if (!(field instanceof HTMLInputElement)) return;
        const available = linked && (value ?? '') !== '';

        // Reset first, unconditionally — then take only what the new business itself provides.
        field.value = available ? value : '';
        // Locked only where the new business has an authoritative value of its own; otherwise the
        // CI may record one, and it is stored on this Business Check alone.
        field.readOnly = available;
    };

    apply('[data-business-check-business-name]', option?.dataset.businessName);
    apply('[data-business-check-location]', option?.dataset.location);
    apply('[data-business-check-ci-date]', option?.dataset.ciDate);
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

// A form marked [data-no-change-guard] refuses to submit while every editable control still
// holds the value the server rendered: instead of issuing an update that would change nothing —
// and would still write history — it reveals its own [data-no-change-message] and stays open.
// Comparison is against each control's own default (the rendered value/checked/selected state),
// so no snapshot timing is involved and a fragment loaded into a modal behaves identically.
const NO_CHANGE_IGNORED_FIELDS = ['_token', '_method', 'co_maker_id', 'expected_updated_at', 'intent'];

function noChangeGuardIsDirty(form) {
    return [...form.elements].some((control) => {
        if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) return false;
        if (control.disabled || control.name === '' || NO_CHANGE_IGNORED_FIELDS.includes(control.name)) return false;

        if (control instanceof HTMLSelectElement) {
            return [...control.options].some((option) => option.selected !== option.defaultSelected);
        }
        if (control instanceof HTMLInputElement) {
            if (['hidden', 'submit', 'button', 'reset'].includes(control.type)) return false;
            if (['checkbox', 'radio'].includes(control.type)) return control.checked !== control.defaultChecked;
            if (control.type === 'file') return (control.files?.length ?? 0) > 0;
        }

        return control.value !== control.defaultValue;
    });
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-no-change-guard]')) return;
    const message = form.querySelector('[data-no-change-message]');
    if (!(message instanceof HTMLElement) || noChangeGuardIsDirty(form)) return;

    // Capture phase: this also stops any page-level submit handler from running.
    event.preventDefault();
    event.stopPropagation();
    message.hidden = false;
}, true);

document.addEventListener('input', (event) => {
    const form = event.target instanceof Element ? event.target.closest('[data-no-change-guard]') : null;
    const message = form?.querySelector('[data-no-change-message]');
    if (message instanceof HTMLElement) message.hidden = true;
});

document.addEventListener('change', (event) => {
    const form = event.target instanceof Element ? event.target.closest('[data-no-change-guard]') : null;
    const message = form?.querySelector('[data-no-change-message]');
    if (message instanceof HTMLElement) message.hidden = true;
});

// Scheduled and For Follow-up CI Activities (or Bank / Coop / Asset targets) need a date;
// time stays optional.
// Each [data-schedule-date] input is paired with the [data-schedule-status] select in its nearest
// [data-schedule-scope] (a repeater row), else in its form. Registered after the no-change guard
// and in the capture phase for the same reason: it runs before page-level submit handlers —
// including forms loaded into a modal after page load — and keeps the form open with an inline
// error instead of letting the request go out. The FormRequests enforce the same rule server-side.
const SCHEDULE_DATE_INVALID_CLASSES = ['border-danger', 'ring-2', 'ring-danger/20'];

function scheduleStatusFor(date) {
    const scope = date.closest('[data-schedule-scope]') ?? date.form;
    return scope?.querySelector('[data-schedule-status]') ?? null;
}

function setScheduleDateInvalid(date, invalid) {
    SCHEDULE_DATE_INVALID_CLASSES.forEach((name) => date.classList.toggle(name, invalid));
    date.setAttribute('aria-invalid', invalid ? 'true' : 'false');
    let error = date.parentElement?.querySelector('[data-schedule-date-error]');
    if (invalid && !(error instanceof HTMLElement)) {
        error = document.createElement('p');
        error.dataset.scheduleDateError = '';
        error.className = 'mt-1.5 text-sm font-semibold text-danger';
        error.setAttribute('role', 'alert');
        error.textContent = 'Please select a scheduled date.';
        date.insertAdjacentElement('afterend', error);
    } else if (!invalid) {
        error?.remove();
    }
}

function syncScheduleDateRequirement(status) {
    const scope = status.closest('[data-schedule-scope]') ?? status.form;
    scope?.querySelectorAll('[data-schedule-date]').forEach((date) => {
        if (!(date instanceof HTMLInputElement) || scheduleStatusFor(date) !== status) return;
        const required = !status.disabled && ['scheduled', 'follow_up'].includes(status.value);
        const label = date.labels?.[0] ?? date.parentElement?.querySelector('label');
        label?.querySelector('[data-schedule-date-optional]')?.toggleAttribute('hidden', required);
        label?.querySelector('[data-schedule-date-required]')?.toggleAttribute('hidden', !required);
        date.required = required;
        date.setAttribute('aria-required', required ? 'true' : 'false');
        if (!required) setScheduleDateInvalid(date, false);
    });
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
    const missing = [...form.querySelectorAll('[data-schedule-date]')].filter((date) => {
        if (!(date instanceof HTMLInputElement) || date.closest('[hidden]')) return false;
        const status = scheduleStatusFor(date);
        return status instanceof HTMLSelectElement && !status.disabled && ['scheduled', 'follow_up'].includes(status.value) && date.value === '';
    });
    if (missing.length === 0) return;

    event.preventDefault();
    event.stopPropagation();
    missing.forEach((date) => setScheduleDateInvalid(date, true));
    missing[0].focus();
}, true);

// Capture phase so programmatic, non-bubbling status changes (e.g. "Follow-up" shortcuts) sync too.
document.addEventListener('change', (event) => {
    const control = event.target;
    if (control instanceof HTMLSelectElement && control.matches('[data-schedule-status]')) syncScheduleDateRequirement(control);
    if (control instanceof HTMLInputElement && control.matches('[data-schedule-date]') && control.value !== '') setScheduleDateInvalid(control, false);
}, true);

document.addEventListener('input', (event) => {
    const date = event.target;
    if (date instanceof HTMLInputElement && date.matches('[data-schedule-date]') && date.value !== '') setScheduleDateInvalid(date, false);
});

/**
 * CUSTOM "OTHER BUSINESS / SOURCE OF INCOME" CHECKBOX OPTIONS — add, rename and remove.
 *
 * Every request goes through fetch rather than a form submit on purpose: the catalog lives inside
 * the Business Report encoding form, so navigating away to manage an option would throw away
 * whatever the CI has already ticked. Nothing else on the page is touched — only the one checkbox
 * row involved is inserted, relabelled or dropped, and the report's own selections stay exactly as
 * the CI left them.
 *
 * A no-op rename is answered by the server with { no_change: true } and shown through the same
 * informational toast the rest of the app uses; the dialog stays open so the CI can correct it.
 */
(() => {
    const manager = () => document.querySelector('[data-custom-business-manager]');
    const dialogFor = (id) => document.getElementById(id);
    const closeDialog = (id) => dialogFor(id)?.close();
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    let editingId = null;
    let removingId = null;

    const showError = (element, message) => {
        if (!(element instanceof HTMLElement)) return;
        element.textContent = message;
        element.hidden = !message;
    };

    const send = async (url, method, body) => {
        const response = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        const payload = await response.json().catch(() => ({}));

        return { ok: response.ok, payload };
    };

    /** The one message the server sent back for a rejected name. */
    const firstError = (payload) => payload?.errors?.name?.[0] ?? payload?.message ?? 'Something went wrong. Please try again.';

    const rowFor = (id) => document.querySelector('[data-custom-business-category="' + id + '"]');

    /**
     * Build the new row by cloning the Blade-rendered <template>, never by re-describing the markup
     * here: that is what keeps a just-added row pixel-identical to a server-rendered one (same
     * classes, same grid columns, same inline icon SVGs) and is why the icons render on the very
     * first add, when there is no existing row to copy them from.
     *
     * Only the two placeholders that are safe as raw markup — the numeric id and the derived
     * custom_<id> key — are substituted textually. The business name is applied through DOM APIs
     * below, so a name can never inject HTML.
     */
    const appendRow = (category) => {
        const list = document.querySelector('[data-custom-business-list]');
        const template = document.querySelector('[data-custom-business-row-template]');
        if (!list || !(template instanceof HTMLTemplateElement)) return;

        const holder = document.createElement('div');
        holder.innerHTML = template.innerHTML
            .replaceAll('__OPTION_KEY__', category.optionKey)
            .replaceAll('__CUSTOM_ID__', String(category.id))
            .trim();

        const row = holder.firstElementChild;
        if (!(row instanceof HTMLElement)) return;

        applyName(row, category.name);
        list.append(row);
    };

    /** Write a category's name into every place the row shows it, as text and attributes only. */
    const applyName = (row, name) => {
        row.dataset.customBusinessName = name;

        const srLabel = row.querySelector('label.sr-only');
        if (srLabel instanceof HTMLElement) srLabel.textContent = 'Select ' + name;

        const text = row.querySelector('[data-custom-business-label]');
        if (text instanceof HTMLElement) text.textContent = name;

        const checkbox = row.querySelector('[data-income-source-choice]');
        // The stored value is the stable custom_<id> key, which a rename never changes.
        if (checkbox instanceof HTMLInputElement) checkbox.dataset.incomeSourceLabel = name;

        const edit = row.querySelector('[data-custom-business-edit]');
        if (edit instanceof HTMLElement) {
            edit.title = 'Edit ' + name;
            edit.setAttribute('aria-label', edit.title);
        }

        const remove = row.querySelector('[data-custom-business-remove]');
        if (remove instanceof HTMLElement) {
            remove.title = 'Remove ' + name;
            remove.setAttribute('aria-label', remove.title);
        }
    };

    document.addEventListener('click', async (event) => {
        if (!(event.target instanceof Element)) return;
        const base = manager()?.dataset.customBusinessBaseUrl;
        if (!base) return;

        if (event.target.closest('[data-custom-business-add]')) {
            const input = document.querySelector('[data-custom-business-add-name]');
            if (input instanceof HTMLInputElement) input.value = '';
            showError(document.querySelector('[data-custom-business-add-error]'), '');

            return;
        }

        const editTrigger = event.target.closest('[data-custom-business-edit]');
        if (editTrigger) {
            const row = editTrigger.closest('[data-custom-business-category]');
            editingId = row?.dataset.customBusinessCategory ?? null;
            const input = document.querySelector('[data-custom-business-edit-name]');
            if (input instanceof HTMLInputElement) input.value = row?.dataset.customBusinessName ?? '';
            showError(document.querySelector('[data-custom-business-edit-error]'), '');
            dialogFor('custom-business-edit-dialog')?.showModal();

            return;
        }

        const removeTrigger = event.target.closest('[data-custom-business-remove]');
        if (removeTrigger) {
            const row = removeTrigger.closest('[data-custom-business-category]');
            removingId = row?.dataset.customBusinessCategory ?? null;
            const message = document.querySelector('[data-custom-business-remove-message]');
            const name = row?.dataset.customBusinessName ?? '';
            if (message instanceof HTMLElement) message.textContent = 'Remove "' + name + '" from the available business options?';
            dialogFor('custom-business-remove-dialog')?.showModal();

            return;
        }

        if (event.target.closest('[data-custom-business-add-submit]')) {
            const input = document.querySelector('[data-custom-business-add-name]');
            const result = await send(base, 'POST', { name: input instanceof HTMLInputElement ? input.value : '' });
            if (!result.ok) {
                showError(document.querySelector('[data-custom-business-add-error]'), firstError(result.payload));

                return;
            }
            appendRow(result.payload.category);
            closeDialog('custom-business-add-dialog');
            showToast(result.payload.message);

            return;
        }

        if (event.target.closest('[data-custom-business-edit-submit]')) {
            if (!editingId) return;
            const input = document.querySelector('[data-custom-business-edit-name]');
            const result = await send(base + '/' + editingId, 'PUT', { name: input instanceof HTMLInputElement ? input.value : '' });
            if (!result.ok) {
                showError(document.querySelector('[data-custom-business-edit-error]'), firstError(result.payload));

                return;
            }
            if (result.payload.no_change) {
                // Nothing was written; the dialog stays open exactly as the CI left it.
                showToast(result.payload.message, 'info');

                return;
            }
            const row = rowFor(editingId);
            if (row instanceof HTMLElement) applyName(row, result.payload.category.name);
            closeDialog('custom-business-edit-dialog');
            showToast(result.payload.message);

            return;
        }

        if (event.target.closest('[data-custom-business-remove-submit]')) {
            if (!removingId) return;
            const result = await send(base + '/' + removingId, 'DELETE');
            if (!result.ok) {
                showToast(firstError(result.payload), 'error');

                return;
            }
            rowFor(removingId)?.remove();
            closeDialog('custom-business-remove-dialog');
            showToast(result.payload.message, result.payload.deleted ? 'success' : 'info');
        }
    });
})();


/**
 * CI Completion Trend range tabs (7 Days / 30 Days / 12 Months).
 *
 * All three ranges are already rendered server-side on the initial dashboard load (see
 * DashboardData::for()'s `trends`), so switching range is a pure show/hide of a pre-rendered chart:
 * no request, no loading state, no flicker. The tabs stay ordinary links to the same `range` GET
 * parameter the controller already honours, so without JS — or on a middle/modifier click — they
 * still navigate exactly as before; this only intercepts the plain left-click.
 */
(() => {
    const tabs = document.querySelector('[data-trend-tabs]');
    if (!tabs) return;

    const ACTIVE = ['bg-brand-primary', 'text-white', 'shadow-sm'];
    const IDLE = ['text-text-muted', 'hover:bg-surface', 'hover:text-brand-primary'];

    tabs.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const tab = event.target.closest('[data-trend-tab]');
        if (!tab) return;

        const range = tab.dataset.trendTab;
        const panels = document.querySelectorAll('[data-trend-panel]');
        const selected = document.querySelector(`[data-trend-panel="${range}"]`);
        // Nothing pre-rendered for this range: leave the link alone and let it navigate.
        if (!selected || panels.length === 0) return;
        event.preventDefault();

        panels.forEach((panel) => {
            panel.hidden = panel !== selected;
        });

        tabs.querySelectorAll('[data-trend-tab]').forEach((other) => {
            const isActive = other === tab;
            other.classList.remove(...(isActive ? IDLE : ACTIVE));
            other.classList.add(...(isActive ? ACTIVE : IDLE));
            if (isActive) {
                other.setAttribute('aria-current', 'true');
            } else {
                other.removeAttribute('aria-current');
            }
        });

        // Keep the address bar honest so a refresh or a shared link reopens the same range,
        // without navigating away from the already-rendered page.
        const url = new URL(window.location.href);
        url.searchParams.set('range', range);
        window.history.replaceState({}, '', url);
    });
})();
