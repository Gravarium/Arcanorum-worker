import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = await readFile(new URL('../../assets/analytics/google-analytics.js', import.meta.url), 'utf8');

function runAnalytics(measurementId, pageType) {
    const appendedScripts = [];
    const window = {
        location: {
            origin: 'https://media.example.test',
            href: 'https://media.example.test/search?q=private-term#private-fragment',
        },
    };
    const document = {
        currentScript: { dataset: { measurementId, pageType } },
        createElement(tagName) {
            return { tagName };
        },
        head: {
            appendChild(element) {
                appendedScripts.push(element);
            },
        },
    };

    runInNewContext(source, { Date, URL, document, window });

    return { appendedScripts, window };
}

test('loads fixed GA4 code only with a valid ID and public page category', () => {
    const { appendedScripts, window } = runAnalytics('G-ABC1234567', 'search');

    assert.equal(appendedScripts.length, 1);
    assert.equal(appendedScripts[0].src, 'https://www.googletagmanager.com/gtag/js?id=G-ABC1234567');
    assert.equal(appendedScripts[0].referrerPolicy, 'no-referrer');
    assert.equal(appendedScripts[0].async, true);

    const calls = window.dataLayer.map((argumentsList) => Array.from(argumentsList));
    assert.equal(calls[1][0], 'config');
    assert.equal(calls[1][2].send_page_view, false);
    assert.equal(calls[1][2].allow_google_signals, false);
    assert.equal(calls[1][2].allow_ad_personalization_signals, false);
    assert.equal(calls[2][0], 'event');
    assert.equal(calls[2][1], 'page_view');
    assert.equal(calls[2][2].page_location, 'https://media.example.test/_analytics/search');
    assert.equal(calls[2][2].page_referrer, '');
    assert.equal(calls[2][2].page_title, 'search');
    assert.equal(JSON.stringify(calls).includes('private-term'), false);
    assert.equal(JSON.stringify(calls).includes('private-fragment'), false);
});

test('does not contact a provider for malformed measurement IDs or non-public page types', () => {
    for (const [measurementId, pageType] of [
        ['G-abc1234567', 'home'],
        ['G-ABC123456', 'video'],
        ['<script>alert(1)</script>', 'home'],
        ['G-ABC1234567', 'member_profile'],
        ['G-ABC1234567', 'admin'],
    ]) {
        const { appendedScripts, window } = runAnalytics(measurementId, pageType);
        assert.equal(appendedScripts.length, 0);
        assert.equal(window.dataLayer, undefined);
    }
});
