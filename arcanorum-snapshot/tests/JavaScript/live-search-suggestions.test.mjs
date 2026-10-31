import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const script = await readFile(new URL('../../public/search/live-search-suggestions.js', import.meta.url), 'utf8');

class FakeElement {
    listeners = new Map();
    children = [];
    dataset = {};
    hidden = false;
    textContent = '';
    href = '';
    className = '';

    addEventListener(name, callback) {
        this.listeners.set(name, callback);
    }

    dispatch(name, event = {}) {
        return this.listeners.get(name)?.({ target: this, ...event });
    }

    append(...children) {
        for (const child of children) {
            this.children.push(...(child instanceof FakeFragment ? child.children : [child]));
        }
    }

    replaceChildren(...children) {
        this.children = [];
        this.append(...children);
    }
}

class FakeFragment extends FakeElement {}

class FakeInput extends FakeElement {
    value = '';
}

class FakeList extends FakeElement {}

function createHarness(fetchImplementation) {
    const input = new FakeInput();
    input.dataset.suggestionsUrl = '/search/suggestions';
    input.dataset.labelVideo = 'Video';
    input.dataset.messageEmpty = 'Keine passenden Vorschläge.';
    input.dataset.messageCount = '%count% Suchvorschläge.';
    input.dataset.messageLimited = 'Zu viele Suchanfragen. Bitte warte kurz.';
    input.dataset.messageUnavailable = 'Suchvorschläge sind derzeit nicht verfügbar.';
    const results = new FakeList();
    const status = new FakeElement();
    const scheduled = new Map();
    let timerId = 0;
    const document = {
        querySelector(selector) {
            return {
                '[data-live-search-input]': input,
                '[data-live-search-results]': results,
                '[data-live-search-status]': status,
            }[selector] ?? null;
        },
        createElement() {
            return new FakeElement();
        },
        createDocumentFragment() {
            return new FakeFragment();
        },
    };
    const window = {
        location: { origin: 'https://cms.example' },
        setTimeout(callback) {
            const id = ++timerId;
            scheduled.set(id, callback);
            return id;
        },
        clearTimeout(id) {
            scheduled.delete(id);
        },
    };

    vm.runInNewContext(script, {
        AbortController,
        document,
        fetch: fetchImplementation,
        HTMLInputElement: FakeInput,
        HTMLUListElement: FakeList,
        HTMLElement: FakeElement,
        URL,
        window,
    });

    return {
        input,
        results,
        status,
        async runScheduled() {
            const [id, callback] = [...scheduled.entries()][0] ?? [];
            assert.equal(typeof callback, 'function');
            scheduled.delete(id);
            await callback();
        },
    };
}

test('fetches suggestions after typing and renders untrusted titles as text and internal links', async () => {
    let requestUrl;
    const harness = createHarness(async (url, options) => {
        requestUrl = new URL(url);
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.cache, 'no-store');
        assert.equal(options.headers.Accept, 'application/json');
        return {
            ok: true,
            headers: { get: () => 'application/json; charset=utf-8' },
            json: async () => ({ suggestions: [{ type: 'video', title: '<img src=x onerror=alert(1)>', url: '/videos/example' }] }),
        };
    });

    harness.input.value = '  sample & term  ';
    harness.input.dispatch('input');
    await harness.runScheduled();

    assert.equal(requestUrl.origin, 'https://cms.example');
    assert.equal(requestUrl.searchParams.get('q'), 'sample & term');
    assert.equal(harness.results.hidden, false);
    assert.equal(harness.results.children.length, 1);
    const [link] = harness.results.children[0].children;
    assert.equal(link.href, '/videos/example');
    assert.equal(link.children[1].textContent, '<img src=x onerror=alert(1)>');
    assert.equal(harness.status.textContent, '1 Suchvorschläge.');
});

test('does not request one-character searches and clears suggestions on Escape', async () => {
    let requests = 0;
    const harness = createHarness(async () => {
        requests += 1;
        throw new Error('A one-character query must not fetch.');
    });

    harness.input.value = 'a';
    harness.input.dispatch('input');
    assert.equal(requests, 0);

    harness.results.hidden = false;
    harness.results.children.push(new FakeElement());
    harness.status.textContent = 'Old suggestions';
    harness.input.dispatch('keydown', { key: 'Escape' });
    assert.equal(harness.results.hidden, true);
    assert.deepEqual(harness.results.children, []);
    assert.equal(harness.status.textContent, '');
});

test('rejects cross-origin destinations and malformed suggestion payloads', async () => {
    const harness = createHarness(async () => ({
        ok: true,
        headers: { get: () => 'application/json' },
        json: async () => ({ suggestions: [{ type: 'video', title: 'External', url: '//evil.example/path' }] }),
    }));

    harness.input.value = 'external';
    harness.input.dispatch('input');
    await harness.runScheduled();

    assert.equal(harness.results.hidden, true);
    assert.equal(harness.results.children.length, 0);
    assert.equal(harness.status.textContent, 'Suchvorschläge sind derzeit nicht verfügbar.');
});
