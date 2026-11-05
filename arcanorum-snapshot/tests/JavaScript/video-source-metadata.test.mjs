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
    required = false;
    listener = null;

    addEventListener(name, listener) {
        assert.equal(name, 'input');
        this.listener = listener;
    }

    input(value) {
        this.value = value;
        this.listener?.();
    }
}

class FakeTextarea extends FakeInput {}

class FakeSelect extends FakeElement {
    value = 'embed';
    listener = null;

    addEventListener(name, listener) {
        assert.equal(name, 'change');
        this.listener = listener;
    }

    change(value) {
        this.value = value;
        assert.equal(typeof this.listener, 'function');
        this.listener();
    }
}

function createHarness(response) {
    const button = new FakeButton();
    const status = new FakeElement();
    const source = new FakeInput();
    source.value = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
    const title = new FakeInput();
    const description = new FakeTextarea();
    const durationMinutes = new FakeInput();
    const durationSeconds = new FakeInput();
    const thumbnailUrl = new FakeInput();
    const sourceType = new FakeSelect();
    const sourceUrlField = new FakeElement();
    const sourceUrlLabel = new FakeElement();
    const sourceFileField = new FakeElement();
    const sourceFile = new FakeInput();
    const sourceHelp = [new FakeElement(), new FakeElement(), new FakeElement()];
    sourceHelp[0].dataset.videoSourceHelp = 'embed';
    sourceHelp[1].dataset.videoSourceHelp = 'url';
    sourceHelp[2].dataset.videoSourceHelp = 'stream';
    const calls = [];
    const form = {
        dataset: {
            videoEdit: '0',
            providerUrlLabel: 'Provider video URL',
            directUrlLabel: 'Direct media URL',
            streamUrlLabel: 'Stream URL',
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
                ['[name="duration_minutes"]', durationMinutes],
                ['[name="duration_seconds_part"]', durationSeconds],
                ['[name="thumbnail_url"]', thumbnailUrl],
                ['[data-video-source-type]', sourceType],
                ['[data-video-source-url-field]', sourceUrlField],
                ['[data-video-source-url-label]', sourceUrlLabel],
                ['[data-video-source-file-field]', sourceFileField],
                ['[data-video-source-file]', sourceFile],
            ]).get(selector);
        },
        querySelectorAll(selector) {
            assert.equal(selector, '[data-video-source-help]');
            return sourceHelp;
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
        HTMLSelectElement: FakeSelect,
        HTMLTextAreaElement: FakeTextarea,
        URL,
        URLSearchParams,
        fetch,
    });

    return {
        button, status, source, title, description, durationMinutes, durationSeconds, thumbnailUrl,
        sourceType, sourceUrlField, sourceUrlLabel, sourceFileField, sourceFile, sourceHelp, calls,
    };
}

test('imports provider fields through the same-origin CSRF endpoint after an explicit click', async () => {
    const state = createHarness({
        ok: true,
        json: async () => ({
            title: 'Video title',
            description: 'Description',
            duration_seconds: 3723,
            thumbnail_url: 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg',
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
    assert.equal(state.durationMinutes.value, '62');
    assert.equal(state.durationSeconds.value, '3');
    assert.equal(state.thumbnailUrl.value, 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg');
    assert.equal(state.status.textContent, 'Metadata imported.');
    assert.equal(state.button.disabled, false);
});

test('keeps a manually entered thumbnail address when provider metadata is applied', async () => {
    const state = createHarness({
        ok: true,
        json: async () => ({
            title: 'Video title',
            description: 'Description',
            duration_seconds: null,
            thumbnail_url: 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg',
        }),
    });
    state.thumbnailUrl.value = 'https://images.example.test/custom-cover.webp';

    await state.button.click();

    assert.equal(state.thumbnailUrl.value, 'https://images.example.test/custom-cover.webp');
});

test('fails closed for invalid provider responses without replacing the administrator’s fields', async () => {
    const state = createHarness({
        ok: true,
        json: async () => ({
            title: 'Provider title',
            description: 'Provider description',
            duration_seconds: 60,
            thumbnail_url: 'https://outside.example.test/image.png',
        }),
    });
    state.title.value = 'Manual title';
    state.description.value = 'Manual description';
    state.durationMinutes.value = '1';
    state.durationSeconds.value = '0';
    state.thumbnailUrl.value = 'https://images.example.test/manual.png';

    await state.button.click();

    assert.equal(state.title.value, 'Manual title');
    assert.equal(state.description.value, 'Manual description');
    assert.equal(state.durationMinutes.value, '1');
    assert.equal(state.durationSeconds.value, '0');
    assert.equal(state.thumbnailUrl.value, 'https://images.example.test/manual.png');
    assert.equal(state.status.textContent, 'Metadata could not be imported.');
    assert.equal(state.button.disabled, false);
});

test('shows only the fields for the selected video source and requires a new upload only for file sources', () => {
    const state = createHarness({ok: true, json: async () => ({})});

    assert.equal(state.button.hidden, false);
    assert.equal(state.sourceUrlField.hidden, false);
    assert.equal(state.sourceFileField.hidden, true);
    assert.equal(state.source.required, true);
    assert.equal(state.sourceFile.required, false);
    assert.equal(state.sourceUrlLabel.textContent, 'Provider video URL');
    assert.equal(state.sourceHelp[0].hidden, false);
    assert.equal(state.sourceHelp[1].hidden, true);

    state.sourceType.change('file');
    state.sourceFile.value = 'selected-upload';

    assert.equal(state.button.hidden, true);
    assert.equal(state.sourceUrlField.hidden, true);
    assert.equal(state.sourceFileField.hidden, false);
    assert.equal(state.source.required, false);
    assert.equal(state.sourceFile.required, true);
    assert.equal(state.sourceHelp[0].hidden, true);

    state.sourceType.change('url');

    assert.equal(state.sourceFileField.hidden, true);
    assert.equal(state.sourceFile.value, '');
    assert.equal(state.source.required, true);
    assert.equal(state.sourceUrlLabel.textContent, 'Direct media URL');
});

test('recognizes pasted provider, direct media and HLS URLs without fetching an arbitrary address', () => {
    const state = createHarness({ok: true, json: async () => ({})});

    state.source.input('https://cdn.example.test/video.mp4?token=not-fetched');
    assert.equal(state.sourceType.value, 'url');
    assert.equal(state.sourceUrlLabel.textContent, 'Direct media URL');
    assert.equal(state.button.hidden, true);

    state.source.input('https://stream.example.test/live/master.m3u8');
    assert.equal(state.sourceType.value, 'stream');
    assert.equal(state.button.hidden, true);

    state.source.input('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    assert.equal(state.sourceType.value, 'embed');
    assert.equal(state.sourceUrlLabel.textContent, 'Provider video URL');
    assert.equal(state.button.hidden, false);
    assert.equal(state.calls.length, 0);
});

test('keeps an administrator-selected source type for URLs without a recognized provider or media suffix', () => {
    const state = createHarness({ok: true, json: async () => ({})});

    state.sourceType.change('stream');
    state.source.input('https://media.example.test/live?channel=main');
    assert.equal(state.sourceType.value, 'stream');
    assert.equal(state.sourceUrlLabel.textContent, 'Stream URL');
    assert.equal(state.sourceHelp[2].hidden, false);

    state.sourceType.change('url');
    state.source.input('https://media.example.test/resolve?id=42');
    assert.equal(state.sourceType.value, 'url');
    assert.equal(state.sourceUrlLabel.textContent, 'Direct media URL');
    assert.equal(state.calls.length, 0);
});
