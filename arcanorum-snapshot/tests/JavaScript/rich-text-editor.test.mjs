import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const editorScript = await readFile(
    new URL('../../assets/admin/rich-text-editor.js', import.meta.url),
    'utf8',
);

class FakeElement {
    listeners = new Map();

    addEventListener(name, callback) {
        this.listeners.set(name, callback);
    }

    closest() {
        return null;
    }
}

class FakeSurface extends FakeElement {
    hidden = true;
    innerHTML = '<p>Initial content</p>';
    focused = false;
    children = [];

    focus() {
        this.focused = true;
    }

    setAttribute() {}

    append(child) {
        this.children.push(child);
    }

    cloneNode() {
        const images = this.children.map((image) => image.cloneNode());
        const serialized = {
            querySelectorAll(selector) {
                assert.equal(selector, 'img[data-rich-text-persist-src]');

                return images.filter((image) => image.hasPersistedSource());
            },
            get innerHTML() {
                return '<p>Initial content</p>' + images.map((image) => image.serialize()).join('');
            },
        };

        return serialized;
    }
}

class FakeImage extends FakeElement {
    attributes = new Map();
    dataset = {};
    alt = '';
    src = '';

    cloneNode() {
        const clone = new FakeImage();
        clone.attributes = new Map(this.attributes);
        clone.dataset = { ...this.dataset };
        clone.alt = this.alt;
        clone.src = this.src;

        return clone;
    }

    getAttribute(name) {
        if ('data-rich-text-persist-src' === name) {
            return this.dataset.richTextPersistSrc ?? null;
        }

        return this.attributes.get(name) ?? null;
    }

    setAttribute(name, value) {
        if ('data-rich-text-persist-src' === name) {
            this.dataset.richTextPersistSrc = value;

            return;
        }
        this.attributes.set(name, value);
        if ('src' === name) {
            this.src = value;
        }
    }

    removeAttribute(name) {
        if ('data-rich-text-persist-src' === name) {
            delete this.dataset.richTextPersistSrc;

            return;
        }
        this.attributes.delete(name);
    }

    hasPersistedSource() {
        return 'string' === typeof this.dataset.richTextPersistSrc;
    }

    serialize() {
        const dataAttribute = this.hasPersistedSource()
            ? ` data-rich-text-persist-src="${this.dataset.richTextPersistSrc}"`
            : '';

        return `<img src="${this.src}" alt="${this.alt}"${dataAttribute}>`;
    }
}

class FakeTextarea extends FakeElement {
    hidden = false;
    required = true;
    value = '';
}

class FakeInput extends FakeElement {
    files = [];
    value = '';
    clicked = false;

    click() {
        this.clicked = true;
    }
}

class FakeForm extends FakeElement {}

class FakeButton extends FakeElement {
    constructor(command, value = '') {
        super();
        this.dataset = { richTextCommand: command, richTextValue: value };
    }

    closest(selector) {
        return 'button[data-rich-text-command]' === selector ? this : null;
    }
}

function createHarness({ link = 'https://example.test/news', uploadUrl = '', fetchResponse = null } = {}) {
    const surface = new FakeSurface();
    const textarea = new FakeTextarea();
    const toolbar = new FakeElement();
    const imageInput = new FakeInput();
    const imageError = new FakeElement();
    imageError.hidden = true;
    imageError.textContent = '';
    const form = new FakeForm();
    const widget = {
        dataset: {
            richTextImageUploadUrl: uploadUrl,
            richTextImageUploadToken: '' === uploadUrl ? '' : 'csrf-token',
        },
        querySelector(selector) {
            return {
                '[data-rich-text-surface]': surface,
                'textarea[data-rich-text-source]': textarea,
                '[role="toolbar"]': toolbar,
                '[data-rich-text-image-input]': imageInput,
                '[data-rich-text-image-error]': imageError,
            }[selector] ?? null;
        },
        closest(selector) {
            return 'form' === selector ? form : null;
        },
    };
    const commands = [];
    const document = {
        querySelectorAll(selector) {
            assert.equal(selector, '[data-rich-text-editor]');

            return [widget];
        },
        createElement(tagName) {
            assert.equal(tagName, 'img');

            return new FakeImage();
        },
        execCommand(...args) {
            commands.push(args);
        },
    };
    class FakeFormData {
        entries = [];

        append(...args) {
            this.entries.push(args);
        }
    }
    const window = {
        location: { href: 'https://cms.example/admin/news/new', origin: 'https://cms.example' },
        prompt() {
            return link;
        },
        getSelection() {
            return null;
        },
        async fetch(...args) {
            return fetchResponse ?? { ok: false, json: async () => ({}) };
        },
    };

    vm.runInNewContext(editorScript, {
        document,
        window,
        URL,
        Element: FakeElement,
        HTMLElement: FakeElement,
        HTMLTextAreaElement: FakeTextarea,
        HTMLFormElement: FakeForm,
        HTMLButtonElement: FakeButton,
        HTMLInputElement: FakeInput,
        FormData: FakeFormData,
    });

    return { commands, form, imageError, imageInput, surface, textarea, toolbar, widget };
}

test('pastes only plain text into the editor surface', () => {
    const harness = createHarness();
    let prevented = false;
    const clipboardTypes = [];
    const event = {
        preventDefault() {
            prevented = true;
        },
        clipboardData: {
            getData(type) {
                clipboardTypes.push(type);

                return 'text/plain' === type ? '<img src=x onerror=alert(1)>' : '<script>alert(1)</script>';
            },
        },
    };

    harness.surface.listeners.get('paste')(event);

    assert.equal(prevented, true);
    assert.deepEqual(clipboardTypes, ['text/plain']);
    assert.deepEqual(harness.commands, [['insertText', false, '<img src=x onerror=alert(1)>']]);
    assert.equal(harness.textarea.value, '<p>Initial content</p>');
});

test('prevents dropped markup and link navigation while editing', () => {
    const harness = createHarness();
    let dropPrevented = false;
    let clickPrevented = false;
    const link = new FakeElement();
    link.closest = (selector) => ('a' === selector ? link : null);

    harness.surface.listeners.get('drop')({ preventDefault: () => { dropPrevented = true; } });
    harness.surface.listeners.get('click')({ target: link, preventDefault: () => { clickPrevented = true; } });

    assert.equal(dropPrevented, true);
    assert.equal(clickPrevented, true);
});

test('rejects executable link schemes and permits safe link protocols', () => {
    const unsafe = createHarness({ link: 'javascript:alert(1)' });
    unsafe.toolbar.listeners.get('click')({ target: new FakeButton('createLink') });
    assert.equal(unsafe.surface.focused, true);
    assert.deepEqual(unsafe.commands, []);

    const safe = createHarness({ link: '../news/article' });
    safe.toolbar.listeners.get('click')({ target: new FakeButton('createLink') });
    assert.deepEqual(safe.commands, [['createLink', false, '../news/article']]);
});

test('rejects an external URL returned by the inline image upload endpoint', async () => {
    const harness = createHarness({
        uploadUrl: '/admin/content-inline-images/news/0123456789abcdef0123456789abcdef',
        fetchResponse: {
            ok: true,
            json: async () => ({ url: 'https://evil.example.test/image.png' }),
        },
    });
    const button = new FakeButton('insertImage');

    harness.toolbar.listeners.get('click')({ target: button });
    assert.equal(harness.imageInput.clicked, true);

    harness.imageInput.files = [{ type: 'image/png', size: 10, name: 'image.png' }];
    await harness.imageInput.listeners.get('change')();

    assert.match(harness.imageError.textContent, /sichere lokale Bildadresse/);
    assert.equal(harness.textarea.value, '');
});

test('previews a category upload privately but writes only its public owner URL into the form', async () => {
    const imageId = 'a'.repeat(64);
    const ownerId = 'b'.repeat(32);
    const harness = createHarness({
        uploadUrl: `/admin/content-inline-images/news-category/${ownerId}`,
        fetchResponse: {
            ok: true,
            json: async () => ({
                url: `/content-inline-images/${imageId}`,
                preview_url: `/admin/content-inline-images/news-category/${ownerId}/${imageId}`,
            }),
        },
    });
    harness.toolbar.listeners.get('click')({ target: new FakeButton('insertImage') });
    harness.imageInput.files = [{ type: 'image/png', size: 10, name: 'image.png' }];

    await harness.imageInput.listeners.get('change')();

    assert.equal(harness.surface.children.length, 1);
    assert.equal(
        harness.surface.children[0].src,
        `/admin/content-inline-images/news-category/${ownerId}/${imageId}`,
    );
    assert.match(harness.textarea.value, new RegExp(`/content-inline-images/${imageId}`));
    assert.doesNotMatch(harness.textarea.value, /\/admin\/content-inline-images/);
    assert.doesNotMatch(harness.textarea.value, /data-rich-text-persist-src/);
    assert.equal(harness.imageError.textContent, '');
});

test('rejects a category preview URL outside the private inline image route', async () => {
    const ownerId = 'b'.repeat(32);
    const imageId = 'a'.repeat(64);
    const invalidPreviewUrls = [
        `/admin/content-inline-images/video-category/${ownerId}/${imageId}`,
        `/admin/content-inline-images/news-category/${'c'.repeat(32)}/${imageId}`,
        `/admin/content-inline-images/news-category/${ownerId}/${imageId}?unsafe=1`,
    ];

    for (const previewUrl of invalidPreviewUrls) {
        const harness = createHarness({
            uploadUrl: `/admin/content-inline-images/news-category/${ownerId}`,
            fetchResponse: {
                ok: true,
                json: async () => ({
                    url: `/content-inline-images/${imageId}`,
                    preview_url: previewUrl,
                }),
            },
        });
        harness.toolbar.listeners.get('click')({ target: new FakeButton('insertImage') });
        harness.imageInput.files = [{ type: 'image/png', size: 10, name: 'image.png' }];

        await harness.imageInput.listeners.get('change')();

        assert.match(harness.imageError.textContent, /sichere private Bildvorschau/);
        assert.equal(harness.surface.children.length, 0);
    }
});
