import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const script = await readFile(new URL('../../assets/video/video-preroll.js', import.meta.url), 'utf8');

class FakeElement extends EventTarget {
    hidden = true;
    textContent = '';
    dataset = {};
    attributes = new Set();
    pauseCount = 0;
    currentTime = 0;
    playbackRate = 1;
    controlsEnabled = true;

    get controls() {
        return this.controlsEnabled;
    }

    set controls(enabled) {
        this.controlsEnabled = enabled;
        if (enabled) {
            this.attributes.add('controls');
        } else {
            this.attributes.delete('controls');
        }
    }

    removeAttribute(name) {
        this.attributes.delete(name);
        if ('controls' === name) {
            this.controlsEnabled = false;
        }
    }

    setAttribute(name) {
        this.attributes.add(name);
        if ('controls' === name) {
            this.controlsEnabled = true;
        }
    }

    hasAttribute(name) {
        return this.attributes.has(name);
    }

    pause() {
        this.pauseCount += 1;
    }
}

function fixture() {
    const advertisement = new FakeElement();
    const contentVideo = new FakeElement();
    const status = new FakeElement();
    contentVideo.attributes.add('autoplay');
    contentVideo.attributes.add('controls');
    contentVideo.attributes.add('data-video-preroll-content');
    status.dataset = {
        startedMessage: 'Start',
        completedMessage: 'Done',
        errorMessage: 'Failed',
    };
    const container = {
        querySelector(selector) {
            return '[data-video-preroll-ad]' === selector ? advertisement : status;
        },
    };
    const document = {
        querySelector(selector) {
            return 'video[data-video-preroll-content]' === selector ? contentVideo : container;
        },
    };

    return {advertisement, contentVideo, document, status};
}

test('holds the content player until the native pre-roll finishes', () => {
    const state = fixture();
    runInNewContext(script, {document: state.document});

    assert.equal(state.contentVideo.controls, false);
    assert.equal(state.contentVideo.hasAttribute('controls'), false);
    assert.equal(state.contentVideo.hasAttribute('data-video-preroll-locked'), true);
    assert.equal(state.contentVideo.hasAttribute('autoplay'), false);
    assert.equal(state.contentVideo.pauseCount, 1);
    assert.equal(state.status.hidden, false);
    assert.equal(state.status.textContent, 'Start');

    state.contentVideo.dispatchEvent(new Event('play'));
    assert.equal(state.contentVideo.pauseCount, 2);

    state.advertisement.dispatchEvent(new Event('ended'));
    assert.equal(state.contentVideo.controls, true);
    assert.equal(state.contentVideo.hasAttribute('data-video-preroll-locked'), false);
    assert.equal(state.contentVideo.hasAttribute('controls'), true);
    assert.equal(state.advertisement.hidden, true);
    assert.equal(state.status.textContent, 'Done');

    state.advertisement.dispatchEvent(new Event('error'));
    assert.equal(state.status.textContent, 'Done');
});

test('releases the content player when the ad media fails', () => {
    const state = fixture();
    runInNewContext(script, {document: state.document});
    state.advertisement.dispatchEvent(new Event('error'));

    assert.equal(state.contentVideo.controls, true);
    assert.equal(state.advertisement.hidden, true);
    assert.equal(state.status.textContent, 'Failed');
});

test('re-enables Video.js controls after the ad finishes', () => {
    const state = fixture();
    const controls = [];
    state.contentVideo.__arcanorumVideoJsPlayer = {
        controls(enabled) {
            controls.push(enabled);
        },
    };
    runInNewContext(script, {document: state.document});

    state.advertisement.dispatchEvent(new Event('ended'));

    assert.deepEqual(controls, [false, true]);
    assert.equal(state.contentVideo.controls, false);
});

test('rejects seeking past the played ad segment and restores normal playback speed', () => {
    const state = fixture();
    runInNewContext(script, {document: state.document});

    state.advertisement.currentTime = 8;
    state.advertisement.dispatchEvent(new Event('seeking'));
    assert.equal(state.advertisement.currentTime, 0);

    state.advertisement.currentTime = 1;
    state.advertisement.dispatchEvent(new Event('timeupdate'));
    state.advertisement.currentTime = 9;
    state.advertisement.dispatchEvent(new Event('seeking'));
    assert.equal(state.advertisement.currentTime, 1);

    state.advertisement.playbackRate = 2;
    state.advertisement.dispatchEvent(new Event('ratechange'));
    assert.equal(state.advertisement.playbackRate, 1);
});

test('does not change a page without an active pre-roll ad', () => {
    const state = fixture();
    state.document.querySelector = (selector) => '[data-video-preroll-container]' === selector ? null : state.contentVideo;
    runInNewContext(script, {document: state.document});

    assert.equal(state.contentVideo.controls, true);
    assert.equal(state.contentVideo.hasAttribute('controls'), true);
    assert.equal(state.contentVideo.pauseCount, 0);
});
