import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';
import {runInNewContext} from 'node:vm';

const script = await readFile(new URL('../../assets/admin/video-source-metadata.js', import.meta.url), 'utf8');

class FakeElement {
    dataset = {};
    textContent = '';
}

class FakeButton extends FakeElement {
    disabled = false;
    listener = null;

    addEventListener(name, listener) {
        assert.equal(name, 'click');
        this.listener = listener;
    }

    click() {
        assert.equal(typeof this.listener, 'function');
        return this.listener();
    }
}

class FakeInput extends FakeElement {
    value = '';
}

class FakeTextarea extends FakeInput {}

function createHarness(response) {
    const button = new FakeButton();
    const status = new FakeElement();
    const source = new FakeInput();
    source.value = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
    const title = new FakeInput();
    const description = new FakeTextarea();
    const duration = new FakeInput();
    const calls = [];
    const form = {
        dataset: {
            metadataEndpoint: '/admin/videos/metadata',
            metadataCsrfToken: 'csrf-token',
            metadataError: 'Metadata could not be imported.',
            metadataLoaded: 'Metadata imported.',
            metadataLoading: 'Loading metadata.',
        },
        querySelector(selector) {
            return new Map([
                ['[data-video-metadata-fetch]', button],
                ['[data-video-metadata-status]', status],
                ['[name="source_url"]', source],
                ['[name="title"]', title],
                ['[name="description"]', description],
                ['[name="duration_seconds"]', duration],
            ]).get(selector);
        },
    };
    const fetch = async (url, options) => {
        calls.push({url, options});
        return response;
    };

    runInNewContext(script, {
        document: {querySelectorAll: () => [form]},
        HTMLButtonElement: FakeButton,
        HTMLElement: FakeElement,
        HTMLInputElement: FakeInput,
        HTMLTextAreaElement: FakeTextarea,
        URLSearchParams,
        fetch,
    });

    return {button, status, source, title, description, duration, calls};
}

test('imports provider fields through the same-origin CSRF endpoint after an explicit click', async () => {
    const state = createHarness({
        ok: true,
        json: async () => ({
            title: 'Video title',
            description: 'Description',
            duration_seconds: 3723,
        }),
    });

    await state.button.click();

    assert.equal(state.calls.length, 1);
    assert.equal(state.calls[0].url, '/admin/videos/metadata');
    assert.equal(state.calls[0].options.method, 'POST');
    assert.equal(state.calls[0].options.credentials, 'same-origin');
    assert.equal(state.calls[0].options.cache, 'no-store');
    assert.deepEqual(Object.fromEntries(new URLSearchParams(state.calls[0].options.body)), {
        _token: 'csrf-token',
        source_url: state.source.value,
    });
    assert.equal(state.title.value, 'Video title');
    assert.equal(state.description.value, 'Description');
    assert.equal(state.duration.value, '3723');
    assert.equal(state.status.textContent, 'Metadata imported.');
    assert.equal(state.button.disabled, false);
});

test('fails closed for invalid provider responses without replacing the administrator’s fields', async () => {
    const state = createHarness({
        ok: true,
        json: async () => ({title: '<script>bad</script>', description: 'x'.repeat(20001), duration_seconds: -1}),
    });
    state.title.value = 'Manual title';
    state.description.value = 'Manual description';
    state.duration.value = '60';

    await state.button.click();

    assert.equal(state.title.value, 'Manual title');
    assert.equal(state.description.value, 'Manual description');
    assert.equal(state.duration.value, '60');
    assert.equal(state.status.textContent, 'Metadata could not be imported.');
    assert.equal(state.button.disabled, false);
});
