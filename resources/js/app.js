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

function showToast(message, type = 'success') {
    const region = document.querySelector('[data-toast-region]');
    if (!region) return;
    const toast = document.createElement('div');
    toast.className = `rounded-card border px-4 py-3 text-sm font-semibold shadow-float ${type === 'error' ? 'border-danger/25 bg-danger-soft text-danger' : 'border-success/25 bg-success-soft text-success'}`;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    toast.textContent = message;
    region.append(toast);
    window.setTimeout(() => toast.remove(), 4500);
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

    const folderTile = event.target.closest('[data-folder-tile]');
    if (folderTile && !event.target.closest('a, button, [role="menu"]')) {
        selectClientFolder(folderTile);
    } else if (!event.target.closest('[data-folder-action-menu]')) {
        closeFolderMenus();
    }

    if (event.target.closest('[data-drawer-toggle]')) setDrawer(true);
    if (event.target.closest('[data-drawer-close], [data-mobile-backdrop]')) setDrawer(false);

    const modalTrigger = event.target.closest('[data-modal-open]');
    if (modalTrigger) {
        event.preventDefault();
        closeFolderMenus();
        const dialog = document.getElementById(modalTrigger.dataset.modalOpen);
        if (dialog instanceof HTMLDialogElement) {
            const cibiFrame = dialog.querySelector('[data-cibi-report-frame]') || dialog.querySelector('[data-business-report-frame]');
            const cibiLoading = dialog.querySelector('[data-cibi-report-loading]') || dialog.querySelector('[data-business-report-loading]');
            const cibiUrl = modalTrigger.dataset.cibiReportUrl || modalTrigger.dataset.businessReportUrl;
            if (cibiFrame instanceof HTMLIFrameElement && cibiUrl) {
                const requestedUrl = new URL(cibiUrl, window.location.href).href;
                if (cibiFrame.src !== requestedUrl) {
                    if (cibiLoading) cibiLoading.hidden = false;
                    cibiFrame.addEventListener('load', () => {
                        if (cibiLoading) cibiLoading.hidden = true;
                    }, { once: true });
                    cibiFrame.src = requestedUrl;
                }
            }
            dialog.dataset.returnFocus = modalTrigger.id || '';
            dialog.showModal();
            dialog.querySelector('[data-modal-close], [autofocus]')?.focus();
        }
    }

    const modalClose = event.target.closest('[data-modal-close]');
    if (modalClose) modalClose.closest('dialog')?.close();

    const toastClose = event.target.closest('[data-toast-close]');
    if (toastClose) toastClose.closest('[data-toast]')?.remove();
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
            const selected = [...section.querySelectorAll('[data-income-source-rank]')]
                .filter((control) => control.value.trim() !== '');
            summary.replaceChildren(...selected.map((control) => {
                const rank = control.value.trim();
                const item = document.createElement('li');
                const label = control.dataset.incomeSourceLabel || 'Income source';
                item.textContent = rank ? `${rank}. ${label}` : label;
                return item;
            }).sort((a, b) => {
                const aRank = Number.parseInt(a.textContent, 10);
                const bRank = Number.parseInt(b.textContent, 10);
                return (Number.isNaN(aRank) ? Number.MAX_SAFE_INTEGER : aRank) - (Number.isNaN(bRank) ? Number.MAX_SAFE_INTEGER : bRank);
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
