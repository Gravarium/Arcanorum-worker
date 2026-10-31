import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';
import {runInNewContext} from 'node:vm';

const script = await readFile(new URL('../../assets/video/provider-embed-consent.js', import.meta.url), 'utf8');

class FakeElement {
    dataset = {};
    textContent = '';
}

class FakeButton extends FakeElement {
    disabled = false;
    removed = false;
    listener = null;
    listenerOptions = null;

    addEventListener(name, listener, options) {
        assert.equal(name, 'click');
        this.listener = listener;
        this.listenerOptions = options;
    }

    remove() {
        this.removed = true;
    }

    click() {
        assert.equal(typeof this.listener, 'function');
        this.listener();
        if (this.listenerOptions?.once) {
            this.listener = null;
        }
    }
}

class FakeFrame {
    dataset = {};
    sandbox = {
        values: [],
        add: (...values) => this.sandbox.values.push(...values),
    };
    src = '';
    title = '';
    loading = '';
    referrerPolicy = '';
    allow = '';
    allowFullscreen = false;
}

function createHarness(embedUrl) {
    const button = new FakeButton();
    const status = new FakeElement();
    const container = {
        dataset: {
            embedUrl,
            embedTitle: 'External video',
            loadLabel: 'Provider loaded',
            errorLabel: 'Provider rejected',
        },
        frame: null,
        querySelector(selector) {
            return '[data-provider-embed-load]' === selector ? button : status;
        },
        append(frame) {
            this.frame = frame;
        },
    };
    const document = {
        querySelectorAll(selector) {
            assert.equal(selector, '[data-provider-embed]');
            return [container];
        },
        createElement(name) {
            assert.equal(name, 'iframe');
            return new FakeFrame();
        },
    };

    runInNewContext(script, {
        document,
        HTMLButtonElement: FakeButton,
        HTMLElement: FakeElement,
        URL,
    });

    return {button, container, status};
}

test('loads a YouTube embed only after the visitor chooses', () => {
    const state = createHarness('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');

    assert.equal(state.container.frame, null);
    state.button.click();

    assert.equal(state.container.frame.src, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
    assert.equal(state.container.frame.title, 'External video');
    assert.equal(state.container.frame.loading, 'lazy');
    assert.equal(state.container.frame.referrerPolicy, 'no-referrer');
    assert.deepEqual(state.container.frame.sandbox.values, ['allow-scripts', 'allow-same-origin', 'allow-presentation']);
    assert.equal(state.container.frame.allow, 'fullscreen; picture-in-picture');
    assert.equal(state.container.frame.allowFullscreen, true);
    assert.equal(state.button.removed, true);
    assert.equal(state.status.textContent, 'Provider loaded');
});

test('loads a private Vimeo video with one strictly validated privacy hash', () => {
    const source = 'https://player.vimeo.com/video/123456789?h=abc123';
    const state = createHarness(source);

    state.button.click();

    assert.equal(state.container.frame.src, source);
    assert.equal(state.status.textContent, 'Provider loaded');
});

test('loads a configured Dailymotion player with its required video identifier', () => {
    const source = 'https://geo.dailymotion.com/player/x8lr5.html?video=x84sh87';
    const state = createHarness(source);

    state.button.click();

    assert.equal(state.container.frame.src, source);
    assert.equal(state.status.textContent, 'Provider loaded');
});

test('loads the additional canonical video providers only after the visitor chooses', () => {
    const approved = [
        'https://www.facebook.com/video/embed?video_id=20531316728',
        'https://drive.google.com/file/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/preview',
        'https://drive.google.com/file/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/preview?resourcekey=0-example_resource-1',
        'https://rutube.ru/play/embed/852e974534e3527f16810a7a19c418b0',
        'https://vk.com/video_ext.php?oid=-123456&id=456239017',
        'https://vk.com/video_ext.php?oid=-123456&id=456239017&hash=abc_123',
        'https://player.youku.com/embed/XNDI0MDAwMDAwMA==',
    ];

    for (const source of approved) {
        const state = createHarness(source);

        assert.equal(state.container.frame, null, source);
        state.button.click();

        assert.equal(state.container.frame.src, source, source);
        assert.equal(state.container.frame.loading, 'lazy', source);
        assert.equal(state.container.frame.referrerPolicy, 'no-referrer', source);
        assert.deepEqual(state.container.frame.sandbox.values, ['allow-scripts', 'allow-same-origin', 'allow-presentation'], source);
        assert.equal(state.status.textContent, 'Provider loaded', source);
    }
});

test('fails closed for unapproved hosts, malformed identifiers, and extra parameters', () => {
    const rejected = [
        'http://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        'https://evil.example/embed/dQw4w9WgXcQ',
        'https://user@player.vimeo.com/video/123456789',
        'https://player.vimeo.com:444/video/123456789',
        'https://player.vimeo.com/video/123456789?h=abc123&autoplay=1',
        'https://player.vimeo.com/video/123456789?h=abc123&h=def456',
        'https://player.vimeo.com/video/123456789?h=%2F%2Fevil',
        'https://geo.dailymotion.com/player/x8lr5.html?video=x84sh87&autoplay=1',
        'https://geo.dailymotion.com/player/x8lr5.html?video=x84sh87&video=x84sh88',
        'https://geo.dailymotion.com/player/x8lr5.html?video=https%3A%2F%2Fevil.example',
        'https://geo.dailymotion.com.evil.example/player/x8lr5.html?video=x84sh87',
        'https://geo.dailymotion.com/player/x8lr5.html?video=x84sh87#fragment',
        'https://www.facebook.com.evil.example/video/embed?video_id=20531316728',
        'https://www.facebook.com/video/embed?video_id=20531316728&video_id=20531316729',
        'https://www.facebook.com/video/embed?video_id=20531316728&autoplay=1',
        'https://drive.google.com.evil.example/file/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/preview',
        'https://drive.google.com/file/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/preview?resourcekey=bad%2Fkey',
        'https://drive.google.com/file/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/preview?resourcekey=key&resourcekey=other',
        'https://drive.google.com/file/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/preview?autoplay=1',
        'https://rutube.ru.evil.example/play/embed/852e974534e3527f16810a7a19c418b0',
        'https://rutube.ru/play/embed/not-a-video-id',
        'https://rutube.ru/play/embed/852e974534e3527f16810a7a19c418b0?autoplay=1',
        'https://vk.com.evil.example/video_ext.php?oid=-123456&id=456239017',
        'https://vk.com/video_ext.php?oid=-123456&id=456239017&id=456239018',
        'https://vk.com/video_ext.php?oid=-123456&id=456239017&hash=%2F%2Fevil',
        'https://vk.com/video_ext.php?oid=-123456&id=456239017&autoplay=1',
        'https://player.youku.com.evil.example/embed/XNDI0MDAwMDAwMA==',
        'https://player.youku.com/embed/short',
        'https://player.youku.com/embed/XNDI0MDAwMDAwMA==?autoplay=1',
    ];

    for (const source of rejected) {
        const state = createHarness(source);
        state.button.click();

        assert.equal(state.container.frame, null, source);
        assert.equal(state.button.disabled, true, source);
        assert.equal(state.status.textContent, 'Provider rejected', source);
    }
});
