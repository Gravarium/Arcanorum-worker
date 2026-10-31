(() => {
    'use strict';

    const input = document.querySelector('[data-live-search-input]');
    const results = document.querySelector('[data-live-search-results]');
    const status = document.querySelector('[data-live-search-status]');

    if (!(input instanceof HTMLInputElement) || !(results instanceof HTMLUListElement) || !(status instanceof HTMLElement)) {
        return;
    }

    let timer = 0;
    let activeRequest = null;
    let requestSequence = 0;

    const clearResults = () => {
        results.replaceChildren();
        results.hidden = true;
        status.textContent = '';
    };

    const isSafeSuggestion = (item) => {
        if (!item || typeof item !== 'object' || !['video', 'news', 'series'].includes(item.type)
            || typeof item.title !== 'string' || item.title.length < 1 || item.title.length > 160
            || typeof item.url !== 'string' || item.url.length > 512
            || !item.url.startsWith('/') || item.url.startsWith('//') || item.url.includes('\\')) {
            return false;
        }

        try {
            return new URL(item.url, window.location.origin).origin === window.location.origin;
        } catch {
            return false;
        }
    };

    const renderSuggestions = (payload) => {
        if (!payload || !Array.isArray(payload.suggestions) || payload.suggestions.length > 15
            || !payload.suggestions.every(isSafeSuggestion)) {
            throw new TypeError('Invalid search suggestions response.');
        }

        const fragment = document.createDocumentFragment();
        for (const suggestion of payload.suggestions) {
            const item = document.createElement('li');
            const link = document.createElement('a');
            const type = document.createElement('span');
            type.className = 'search-suggestion-type';
            type.textContent = input.dataset[`label${suggestion.type[0].toUpperCase()}${suggestion.type.slice(1)}`] || '';
            const title = document.createElement('span');
            title.textContent = suggestion.title;
            link.href = suggestion.url;
            link.append(type, title);
            item.append(link);
            fragment.append(item);
        }

        results.replaceChildren(fragment);
        results.hidden = payload.suggestions.length === 0;
        status.textContent = payload.suggestions.length === 0
            ? input.dataset.messageEmpty || ''
            : (input.dataset.messageCount || '%count%').replace('%count%', String(payload.suggestions.length));
    };

    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        activeRequest?.abort();
        activeRequest = null;
        const query = input.value.trim();
        clearResults();

        if ([...query].length < 2 || [...query].length > 100) {
            return;
        }

        const sequence = ++requestSequence;
        timer = window.setTimeout(async () => {
            const controller = new AbortController();
            activeRequest = controller;
            try {
                const endpoint = new URL(input.dataset.suggestionsUrl || '', window.location.origin);
                if (endpoint.origin !== window.location.origin || !endpoint.pathname.startsWith('/search/')) {
                    throw new TypeError('Invalid search suggestion endpoint.');
                }
                endpoint.searchParams.set('q', query);
                const response = await fetch(endpoint, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                    signal: controller.signal,
                });
                if (sequence !== requestSequence || controller.signal.aborted) {
                    return;
                }
                if (!response.ok) {
                    clearResults();
                    status.textContent = response.status === 429
                        ? input.dataset.messageLimited || ''
                        : input.dataset.messageUnavailable || '';
                    return;
                }
                const contentType = response.headers.get('Content-Type') || '';
                if (!contentType.toLowerCase().startsWith('application/json')) {
                    throw new TypeError('Unexpected search suggestions content type.');
                }
                renderSuggestions(await response.json());
            } catch (error) {
                if (error?.name !== 'AbortError' && sequence === requestSequence) {
                    clearResults();
                    status.textContent = input.dataset.messageUnavailable || '';
                }
            } finally {
                if (sequence === requestSequence) {
                    activeRequest = null;
                }
            }
        }, 300);
    });

    input.addEventListener('keydown', (event) => {
        if ('Escape' === event.key) {
            window.clearTimeout(timer);
            activeRequest?.abort();
            clearResults();
        }
    });
})();
