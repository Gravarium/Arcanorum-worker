import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const selectorScript = await readFile(
    new URL('../../assets/video/video-quality-selector.js', import.meta.url),
    'utf8',
);

function createHarness({ url = '/media/720.mp4', mimeType = 'video/mp4' } = {}) {
    const status = { textContent: '' };
    const changeHandlers = new Map();
    const option = { value: url, dataset: { mimeType } };
    const selector = {
        dataset: {
            videoPlayerId: 'test-video',
            qualityMessageChanged: 'Quality changed',
            qualityMessageError: 'Quality change failed',
        },
        selectedIndex: 1,
        selectedOptions: {
            item(index) {
                return index === 0 ? option : null;
            },
        },
        addEventListener(name, callback) {
            changeHandlers.set(name, callback);
        },
        parentElement: {
            querySelector() {
                return status;
            },
        },
    };

    class FakeVideoElement {
        currentTime = 0;
        duration = 120;
        paused = true;
        src = '';
        loadCalls = 0;
        playCalls = 0;
        metadataHandler;

        addEventListener(name, callback) {
            if (name === 'loadedmetadata') {
                this.metadataHandler = callback;
            }
        }

        load() {
            this.loadCalls += 1;
            this.currentTime = 0;
        }

        play() {
            this.playCalls += 1;
            this.paused = false;
            return Promise.resolve();
        }
    }

    const video = new FakeVideoElement();
    const document = {
        baseURI: 'https://cms.example/watch',
        querySelectorAll(selectorName) {
            assert.equal(selectorName, 'select[data-video-quality-selector]');
            return [selector];
        },
        getElementById(id) {
            assert.equal(id, 'test-video');
            return video;
        },
    };

    vm.runInNewContext(selectorScript, {
        document,
        HTMLVideoElement: FakeVideoElement,
        URL,
        window: { location: { origin: 'https://cms.example' } },
    });

    return {
        selector,
        status,
        video,
        change() {
            const handler = changeHandlers.get('change');
            assert.equal(typeof handler, 'function');
            handler();
        },
    };
}

test('loads a valid quality source and announces the change', () => {
    const harness = createHarness({ url: 'https://media.example/720.mp4' });

    harness.change();

    assert.equal(harness.video.src, 'https://media.example/720.mp4');
    assert.equal(harness.video.loadCalls, 1);
    assert.equal(harness.status.textContent, 'Quality changed');
});

test('rejects insecure cross-origin sources without loading them', () => {
    const harness = createHarness({ url: 'http://media.example/720.mp4' });

    harness.change();

    assert.equal(harness.video.loadCalls, 0);
    assert.equal(harness.selector.selectedIndex, 0);
    assert.equal(harness.status.textContent, 'Quality change failed');
});

test('rejects unsupported media types without loading them', () => {
    const harness = createHarness({ mimeType: 'text/html' });

    harness.change();

    assert.equal(harness.video.loadCalls, 0);
    assert.equal(harness.selector.selectedIndex, 0);
    assert.equal(harness.status.textContent, 'Quality change failed');
});

test('restores playback position and playing state after metadata loads', () => {
    const harness = createHarness();
    harness.video.currentTime = 42;
    harness.video.paused = false;

    harness.change();
    assert.equal(harness.video.currentTime, 0);
    harness.video.metadataHandler();

    assert.equal(harness.video.currentTime, 42);
    assert.equal(harness.video.playCalls, 1);
});

test('uses the Video.js source and playback APIs when that engine is active', () => {
    const harness = createHarness({ url: 'https://media.example/720.webm', mimeType: 'video/webm' });
    let currentTime = 19;
    let metadataHandler;
    let selectedSource;
    let playCalls = 0;
    const player = {
        currentTime(value) {
            if (arguments.length > 0) {
                currentTime = value;
            }

            return currentTime;
        },
        duration() {
            return 80;
        },
        paused() {
            return false;
        },
        one(name, callback) {
            assert.equal(name, 'loadedmetadata');
            metadataHandler = callback;
        },
        src(source) {
            selectedSource = source;
        },
        play() {
            playCalls += 1;
            return Promise.resolve();
        },
    };
    harness.video.__arcanorumVideoJsPlayer = player;

    harness.change();
    metadataHandler();

    assert.equal(selectedSource.src, 'https://media.example/720.webm');
    assert.equal(selectedSource.type, 'video/webm');
    assert.equal(currentTime, 19);
    assert.equal(playCalls, 1);
});
