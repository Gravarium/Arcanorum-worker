import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const script = await readFile(new URL('../../assets/comments/emoji-picker.js', import.meta.url), 'utf8');
const unicodeCatalog = JSON.parse(await readFile(new URL('../../src/Module/Comment/Service/emoji-catalog.json', import.meta.url), 'utf8'));
const publicCatalog = {
    categories: unicodeCatalog.categories.map(({ key, items }) => ({ key, items })),
};

class FakeElement {
    listeners = new Map();
    children = [];
    attributes = new Map();
    hidden = false;
    focused = false;
    disabled = false;
    dataset = {};
    textContent = '';

    constructor(dataset = {}) {
        this.dataset = dataset;
    }

    addEventListener(name, callback) {
        this.listeners.set(name, callback);
    }

    dispatch(name, event = {}) {
        return this.listeners.get(name)?.({ target: this, ...event });
    }

    setAttribute(name, value) {
        this.attributes.set(name, value);
    }

    append(child) {
        this.children.push(...(child instanceof FakeFragment ? child.children : [child]));
    }

    replaceChildren(...children) {
        this.children = [];
        for (const child of children) {
            this.append(child);
        }
    }

    descendants() {
        return this.children.flatMap((child) => [child, ...child.descendants()]);
    }

    matches(selector) {
        if ('[data-emoji-item]' === selector) {
            return undefined !== this.dataset.emojiItem;
        }
        if ('[data-emoji-category]' === selector) {
            return undefined !== this.dataset.emojiCategory;
        }
        if ('[data-emoji-catalog-item]' === selector) {
            return undefined !== this.dataset.emojiCatalogItem;
        }
        if ('[data-emoji-catalog-category]' === selector) {
            return undefined !== this.dataset.emojiCatalogCategory;
        }
        return false;
    }

    querySelectorAll(selector) {
        return this.descendants().filter((element) => element.matches(selector));
    }

    querySelector() {
        return null;
    }

    closest(selector) {
        return this.matches(selector) ? this : null;
    }

    contains(element) {
        return this === element || this.descendants().includes(element);
    }

    focus() {
        this.focused = true;
    }
}

class FakeFragment extends FakeElement {}

class FakeInput extends FakeElement {
    value = '';
    maxLength = 3000;
    selectionStart = 0;
    selectionEnd = 0;
    events = [];

    setRangeText(value, start, end, selectionMode) {
        assert.equal(selectionMode, 'end');
        this.value = this.value.slice(0, start) + value + this.value.slice(end);
        this.selectionStart = this.selectionEnd = start + value.length;
    }

    dispatchEvent(event) {
        this.events.push(event);
    }
}

class FakeSection extends FakeElement {
    constructor(key, label, catalog = false) {
        super(catalog ? { emojiCatalogCategory: key } : { emojiCategory: key });
        this.label = label;
        this.items = new FakeElement();
        this.children = [this.items];
    }

    querySelector(selector) {
        return '[data-emoji-items]' === selector ? this.items : null;
    }

    querySelectorAll(selector) {
        return this.items.querySelectorAll(selector);
    }
}

class FakeCategories extends FakeElement {
    constructor(sections) {
        super();
        this.children = sections;
    }
}

class FakeDialog extends FakeElement {
    open = false;

    showModal() {
        this.open = true;
    }

    close() {
        this.open = false;
        this.dispatch('close');
    }
}

class FakePicker extends FakeElement {
    constructor({ trigger, dialog, search, status, close, categories, targetId, fetchUrl }) {
        super({
            emojiTarget: targetId,
            emojiCatalogUrl: fetchUrl,
            emojiEmptyMessage: 'No emojis found.',
            emojiLimitMessage: 'Comment limit reached.',
            emojiLoadingMessage: 'Loading emojis …',
            emojiLoadErrorMessage: 'Emojis could not be loaded.',
            emojiInsertLabel: 'Insert emoji: %name%',
        });
        this.elements = {
            '[data-emoji-picker-open]': trigger,
            '[data-emoji-picker-dialog]': dialog,
            '[data-emoji-search]': search,
            '[data-emoji-status]': status,
            '[data-emoji-picker-close]': close,
            '[data-emoji-picker-categories]': categories,
        };
    }

    querySelector(selector) {
        return this.elements[selector] ?? null;
    }
}

class FakeCatalog extends FakeElement {
    constructor({ search, status, categories }) {
        super();
        this.elements = {
            '[data-emoji-catalog-search]': search,
            '[data-emoji-catalog-status]': status,
        };
        this.children = categories;
    }

    querySelector(selector) {
        return this.elements[selector] ?? null;
    }
}

function createPickerHarness(fetchImplementation = async () => ({ ok: true, json: async () => publicCatalog })) {
    const trigger = new FakeElement();
    const close = new FakeElement();
    const search = new FakeInput();
    const status = new FakeElement();
    const sections = unicodeCatalog.categories.map(({ key, label }) => new FakeSection(key, label));
    const categories = new FakeCategories(sections);
    const dialog = new FakeDialog();
    dialog.children = [categories];
    const picker = new FakePicker({
        trigger,
        dialog,
        search,
        status,
        close,
        categories,
        targetId: 'comment-body',
        fetchUrl: '/hilfe/emoji/katalog.json',
    });
    const target = new FakeInput();
    target.value = 'hello world';
    target.selectionStart = 6;
    target.selectionEnd = 11;
    const fetchCalls = [];
    const document = {
        querySelectorAll(selector) {
            return '[data-emoji-picker]' === selector ? [picker] : [];
        },
        getElementById(id) {
            return 'comment-body' === id ? target : null;
        },
        createElement() {
            return new FakeElement();
        },
        createDocumentFragment() {
            return new FakeFragment();
        },
    };

    vm.runInNewContext(script, {
        document,
        Element: FakeElement,
        Event: class {
            constructor(type, options) {
                this.type = type;
                this.bubbles = options.bubbles;
            }
        },
        fetch: async (url, options) => {
            fetchCalls.push({ url, options });
            return fetchImplementation(url, options);
        },
    });

    return { categories, dialog, fetchCalls, picker, search, sections, status, target, trigger };
}

test('loads the full local catalog on demand and inserts at the selected cursor range', async () => {
    const harness = createPickerHarness();

    assert.equal(harness.trigger.hidden, false);
    assert.equal(harness.search.disabled, true);
    assert.equal(harness.fetchCalls.length, 0);
    await harness.trigger.dispatch('click');
    assert.equal(harness.dialog.open, true);
    assert.equal(harness.fetchCalls.length, 1);
    assert.equal(harness.fetchCalls[0].url, '/hilfe/emoji/katalog.json');
    assert.equal(harness.fetchCalls[0].options.credentials, 'same-origin');
    assert.equal(harness.search.focused, true);
    assert.equal(harness.search.disabled, false);

    const items = harness.dialog.querySelectorAll('[data-emoji-item]');
    assert.equal(items.length, 3963);
    const smile = items.find((item) => '😀' === item.dataset.emojiValue);
    const heart = items.find((item) => '❤️' === item.dataset.emojiValue);
    assert.ok(smile);
    assert.ok(heart);

    harness.search.value = 'heart';
    harness.search.dispatch('input');
    assert.equal(smile.hidden, true);
    assert.equal(heart.hidden, false);

    harness.dialog.dispatch('click', { target: heart });
    assert.equal(harness.target.value, 'hello ❤️');
    assert.equal(harness.target.selectionStart, 8);
    assert.equal(harness.target.events[0].type, 'input');
    assert.equal(harness.dialog.open, false);
    assert.equal(harness.target.focused, true);

    await harness.trigger.dispatch('click');
    assert.equal(harness.fetchCalls.length, 1);
});

test('keeps the dialog open and reports when an emoji would exceed maxlength', async () => {
    const harness = createPickerHarness();
    harness.target.value = 'hello';
    harness.target.maxLength = 5;
    harness.target.selectionStart = 5;
    harness.target.selectionEnd = 5;
    await harness.trigger.dispatch('click');
    const heart = harness.dialog.querySelectorAll('[data-emoji-item]').find((item) => '❤️' === item.dataset.emojiValue);

    harness.dialog.dispatch('click', { target: heart });

    assert.equal(harness.target.value, 'hello');
    assert.equal(harness.dialog.open, true);
    assert.equal(harness.status.textContent, 'Comment limit reached.');
});

test('fails closed when the local catalog endpoint cannot be loaded', async () => {
    const harness = createPickerHarness(async () => ({ ok: false, json: async () => ({}) }));

    await harness.trigger.dispatch('click');

    assert.equal(harness.dialog.open, true);
    assert.equal(harness.search.disabled, true);
    assert.equal(harness.status.textContent, 'Emojis could not be loaded.');
    assert.equal(harness.dialog.querySelectorAll('[data-emoji-item]').length, 0);
});

test('does not render a partial catalog when a category is duplicated', async () => {
    const incompleteCatalog = {
        categories: unicodeCatalog.categories.map(({ key }) => ({ key, items: [] })),
    };
    incompleteCatalog.categories[0].items.push({ value: '😀', label: 'grinning face', search: 'face' });
    incompleteCatalog.categories.at(-1).key = incompleteCatalog.categories[0].key;
    const harness = createPickerHarness(async () => ({ ok: true, json: async () => incompleteCatalog }));

    await harness.trigger.dispatch('click');

    assert.equal(harness.dialog.open, true);
    assert.equal(harness.search.disabled, true);
    assert.equal(harness.status.textContent, 'Emojis could not be loaded.');
    assert.equal(harness.dialog.querySelectorAll('[data-emoji-item]').length, 0);
});

test('filters the public help catalog and reports an empty search result', () => {
    const search = new FakeInput();
    search.dataset.emojiEmptyMessage = 'No emojis found.';
    const status = new FakeElement();
    const cat = new FakeElement({ emojiCatalogItem: '', emojiLabel: 'Katze', emojiSearch: 'cat kitten', emojiValue: '🐱' });
    const dog = new FakeElement({ emojiCatalogItem: '', emojiLabel: 'Hund', emojiSearch: 'dog puppy', emojiValue: '🐶' });
    const animals = new FakeSection('nature', 'Animals', true);
    const symbols = new FakeSection('symbols', 'Symbols', true);
    animals.items.children = [cat, dog];
    const catalog = new FakeCatalog({ search, status, categories: [animals, symbols] });
    const document = {
        querySelectorAll(selector) {
            if ('[data-emoji-picker]' === selector) {
                return [];
            }
            return '[data-emoji-catalog]' === selector ? [catalog] : [];
        },
    };

    vm.runInNewContext(script, { document, Element: FakeElement, Event: class {} });

    search.value = 'kitten';
    search.dispatch('input');
    assert.equal(cat.hidden, false);
    assert.equal(dog.hidden, true);
    assert.equal(animals.hidden, false);
    assert.equal(symbols.hidden, true);
    assert.equal(status.textContent, '');

    search.value = 'not-an-emoji';
    search.dispatch('input');
    assert.equal(animals.hidden, true);
    assert.equal(status.textContent, 'No emojis found.');
});
