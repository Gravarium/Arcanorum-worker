(() => {
    const toggle = document.querySelector('[data-admin-shortcuts-toggle]');
    const help = document.querySelector('[data-admin-shortcuts-help]');

    if (!(toggle instanceof HTMLButtonElement) || !(help instanceof HTMLDetailsElement)) {
        return;
    }

    const destinations = new Map(
        [...document.querySelectorAll('a[data-admin-shortcut]')]
            .filter((link) => link instanceof HTMLAnchorElement)
            .map((link) => [link.dataset.adminShortcut, link]),
    );
    const preferenceKey = 'arcanorum.admin.shortcuts.enabled';
    const enabledLabel = toggle.dataset.enabledLabel || '';
    const disabledLabel = toggle.dataset.disabledLabel || '';
    const enabledAccessibleLabel = toggle.dataset.enabledAccessibleLabel || '';
    const disabledAccessibleLabel = toggle.dataset.disabledAccessibleLabel || '';
    const shortcutHelpItems = document.querySelectorAll('[data-admin-shortcut-help]');

    for (const item of shortcutHelpItems) {
        if (item instanceof HTMLElement && !destinations.has(item.dataset.adminShortcutHelp || '')) {
            item.hidden = true;
        }
    }

    let enabled = false;
    try {
        enabled = window.sessionStorage.getItem(preferenceKey) === '1';
    } catch {
        enabled = false;
    }

    function setEnabled(nextEnabled) {
        enabled = nextEnabled;
        toggle.setAttribute('aria-pressed', String(enabled));
        toggle.setAttribute('aria-label', enabled ? enabledAccessibleLabel : disabledAccessibleLabel);
        toggle.textContent = enabled ? enabledLabel : disabledLabel;

        try {
            window.sessionStorage.setItem(preferenceKey, enabled ? '1' : '0');
        } catch {
            // The button state remains usable when browser storage is unavailable.
        }
    }

    let prefixActive = false;
    let prefixTimer;

    function clearPrefix() {
        prefixActive = false;
        window.clearTimeout(prefixTimer);
    }

    setEnabled(enabled);
    toggle.addEventListener('click', () => setEnabled(!enabled));
    document.addEventListener('focusin', clearPrefix);
    document.addEventListener('click', clearPrefix);
    document.addEventListener('keydown', (event) => {
        if (!enabled || event.defaultPrevented || event.isComposing || event.repeat
            || event.ctrlKey || event.metaKey || event.altKey
        ) {
            clearPrefix();

            return;
        }

        const target = event.target;
        if (target instanceof Element
            && target.closest('input, textarea, select, button, a, [contenteditable]:not([contenteditable="false"]), [role="textbox"]')
        ) {
            clearPrefix();

            return;
        }

        if ('?' === event.key) {
            event.preventDefault();
            help.open = !help.open;
            clearPrefix();

            return;
        }

        if ('g' === event.key.toLowerCase()) {
            clearPrefix();
            prefixActive = true;
            prefixTimer = window.setTimeout(clearPrefix, 1000);

            return;
        }

        if (!prefixActive) {
            return;
        }

        const destination = destinations.get(`g ${event.key.toLowerCase()}`);
        clearPrefix();

        if (destination instanceof HTMLAnchorElement) {
            event.preventDefault();
            window.location.assign(destination.href);
        }
    });
})();
