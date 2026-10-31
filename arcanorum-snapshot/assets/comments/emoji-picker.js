(() => {
    const filterCatalog = (container, input, itemSelector, categorySelector, status, emptyMessage) => {
        const query = input.value.trim().toLocaleLowerCase();
        let visibleCount = 0;
        const items = Array.from(container.querySelectorAll(itemSelector));

        for (const item of items) {
            const searchable = [item.dataset.emojiLabel, item.dataset.emojiSearch, item.dataset.emojiValue]
                .filter(Boolean)
                .join(' ')
                .toLocaleLowerCase();
            const visible = '' === query || searchable.includes(query);
            item.hidden = !visible;
            if (visible) {
                visibleCount += 1;
            }
        }

        for (const category of container.querySelectorAll(categorySelector)) {
            category.hidden = !Array.from(category.querySelectorAll(itemSelector)).some((item) => !item.hidden);
        }

        status.textContent = 0 === visibleCount && '' !== query ? emptyMessage : '';
    };

    const buildEmojiButton = (item, insertLabel) => {
        if (!item || 'string' !== typeof item.value || '' === item.value || 'string' !== typeof item.label || '' === item.label || 'string' !== typeof item.search) {
            throw new TypeError('Invalid emoji catalog item.');
        }

        const button = document.createElement('button');
        button.className = 'emoji-picker-item';
        button.type = 'button';
        button.dataset.emojiItem = '';
        button.dataset.emojiValue = item.value;
        button.dataset.emojiLabel = item.label;
        button.dataset.emojiSearch = item.search;
        button.setAttribute('aria-label', insertLabel.replace('%name%', item.label));

        const glyph = document.createElement('span');
        glyph.setAttribute('aria-hidden', 'true');
        glyph.textContent = item.value;
        button.append(glyph);

        return button;
    };

    const loadEmojiCatalog = async (root, categories, insertLabel) => {
        const sections = new Map(Array.from(categories.querySelectorAll('[data-emoji-category]'))
            .map((section) => [section.dataset.emojiCategory, section]));
        const response = await fetch(root.dataset.emojiCatalogUrl ?? '', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            redirect: 'error',
        });
        if (!response.ok) {
            throw new Error('Emoji catalog request failed.');
        }

        const payload = await response.json();
        if (!payload || !Array.isArray(payload.categories)) {
            throw new TypeError('Invalid emoji catalog response.');
        }
        if (payload.categories.length !== sections.size) {
            throw new TypeError('Incomplete emoji catalog response.');
        }

        const seen = new Set();
        const fragments = new Map();
        for (const category of payload.categories) {
            if (!category || 'string' !== typeof category.key || !Array.isArray(category.items)) {
                throw new TypeError('Invalid emoji category.');
            }

            const section = sections.get(category.key);
            if (!section || fragments.has(category.key)) {
                throw new TypeError('Unexpected emoji category.');
            }

            const fragment = document.createDocumentFragment();
            for (const item of category.items) {
                if (!item || 'string' !== typeof item.value || '' === item.value) {
                    throw new TypeError('Invalid emoji catalog item.');
                }
                if (seen.has(item?.value)) {
                    throw new TypeError('Duplicate emoji catalog item.');
                }
                seen.add(item?.value);
                fragment.append(buildEmojiButton(item, insertLabel));
            }

            fragments.set(category.key, fragment);
        }

        if (0 === seen.size) {
            throw new TypeError('Empty emoji catalog response.');
        }

        for (const [key, fragment] of fragments) {
            const target = sections.get(key)?.querySelector('[data-emoji-items]');
            if (!target) {
                throw new TypeError('Unexpected emoji category target.');
            }
            target.replaceChildren(fragment);
        }
    };

    for (const root of document.querySelectorAll('[data-emoji-picker]')) {
        const trigger = root.querySelector('[data-emoji-picker-open]');
        const dialog = root.querySelector('[data-emoji-picker-dialog]');
        const search = root.querySelector('[data-emoji-search]');
        const status = root.querySelector('[data-emoji-status]');
        const categories = root.querySelector('[data-emoji-picker-categories]');
        const targetId = root.dataset.emojiTarget;
        const target = 'string' === typeof targetId ? document.getElementById(targetId) : null;

        if (!trigger || !dialog || !search || !status || !categories || !target || 'function' !== typeof dialog.showModal) {
            continue;
        }

        trigger.hidden = false;
        search.disabled = true;
        let focusTargetAfterClose = false;
        let catalogPromise = null;
        search.addEventListener('input', () => {
            filterCatalog(
                dialog,
                search,
                '[data-emoji-item]',
                '[data-emoji-category]',
                status,
                root.dataset.emojiEmptyMessage ?? '',
            );
        });
        trigger.addEventListener('click', async () => {
            focusTargetAfterClose = false;
            dialog.showModal();

            if (null === catalogPromise) {
                status.textContent = root.dataset.emojiLoadingMessage ?? '';
                catalogPromise = loadEmojiCatalog(root, categories, root.dataset.emojiInsertLabel ?? 'Insert %name%')
                    .then(() => true)
                    .catch(() => {
                        catalogPromise = null;
                        status.textContent = root.dataset.emojiLoadErrorMessage ?? '';
                        return false;
                    });
            }

            if (await catalogPromise) {
                if (!dialog.open) {
                    return;
                }

                search.disabled = false;
                status.textContent = '';
                search.focus();
            }
        });
        root.querySelector('[data-emoji-picker-close]')?.addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => {
            if (focusTargetAfterClose) {
                focusTargetAfterClose = false;
                target.focus();
            } else {
                trigger.focus();
            }
        });
        dialog.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) {
                return;
            }

            const button = event.target.closest('[data-emoji-item]');
            if (!button || !dialog.contains(button)) {
                return;
            }

            const emoji = button.dataset.emojiValue;
            if ('string' !== typeof emoji || '' === emoji) {
                return;
            }

            const start = Number.isInteger(target.selectionStart) ? target.selectionStart : target.value.length;
            const end = Number.isInteger(target.selectionEnd) ? target.selectionEnd : start;
            const nextLength = target.value.length - (end - start) + emoji.length;
            if (target.maxLength >= 0 && nextLength > target.maxLength) {
                status.textContent = root.dataset.emojiLimitMessage ?? '';
                return;
            }

            target.setRangeText(emoji, start, end, 'end');
            target.dispatchEvent(new Event('input', { bubbles: true }));
            focusTargetAfterClose = true;
            dialog.close();
        });
    }

    for (const catalog of document.querySelectorAll('[data-emoji-catalog]')) {
        const search = catalog.querySelector('[data-emoji-catalog-search]');
        const status = catalog.querySelector('[data-emoji-catalog-status]');
        if (!search || !status) {
            continue;
        }

        search.addEventListener('input', () => {
            filterCatalog(
                catalog,
                search,
                '[data-emoji-catalog-item]',
                '[data-emoji-catalog-category]',
                status,
                search.dataset.emojiEmptyMessage ?? '',
            );
        });
    }
})();
