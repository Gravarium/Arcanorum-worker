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

    focus() {
        this.focused = true;
    }

    setAttribute() {}
}

class FakeTextarea extends FakeElement {
    hidden = false;
    required = true;
    value = '';
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

function createHarness({ link = 'https://example.test/news', linkCommand = 'createLink' } = {}) {
    const surface = new FakeSurface();
    const textarea = new FakeTextarea();
    const toolbar = new FakeElement();
    const form = new FakeForm();
    const widget = {
        querySelector(selector) {
            return {
                '[data-rich-text-surface]': surface,
                'textarea[name="body"]': textarea,
                '[role="toolbar"]': toolbar,
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
        execCommand(...args) {
            commands.push(args);
        },
    };
    const window = {
        location: { href: 'https://cms.example/admin/news/new' },
        prompt() {
            return link;
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
    });

    return { commands, form, surface, textarea, toolbar };
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
