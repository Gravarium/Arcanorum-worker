import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const script = await readFile(new URL('../../assets/advertising/placement-formats.js', import.meta.url), 'utf8');

function fixture(initialFormats) {
    const options = ['html_banner', 'static_player', 'video'].map((value) => {
        const input = {value, checked: false, disabled: false};
        return {input, hidden: false, querySelector: () => input};
    });
    const select = {
        selectedOptions: [{dataset: {formats: initialFormats}}],
        listeners: {},
        addEventListener(name, callback) {
            this.listeners[name] = callback;
        },
    };
    const document = {
        querySelector: () => select,
        querySelectorAll: () => options,
    };

    return {document, options, select};
}

test('locks allowed format choices to the selected placement', () => {
    const state = fixture('video');
    state.options[2].input.checked = true;
    runInNewContext(script, {document: state.document});

    assert.deepEqual(state.options.map(({input}) => [input.value, input.checked, input.disabled]), [
        ['html_banner', false, true],
        ['static_player', false, true],
        ['video', true, false],
    ]);
    assert.deepEqual(state.options.map(({hidden}) => hidden), [true, true, false]);

    state.select.selectedOptions = [{dataset: {formats: 'static_player,video'}}];
    state.select.listeners.change();
    assert.deepEqual(state.options.map(({input}) => [input.checked, input.disabled]), [
        [false, true],
        [false, false],
        [true, false],
    ]);
});

test('leaves the form alone when the placement selector is missing', () => {
    let queried = false;
    runInNewContext(script, {
        document: {querySelector: () => null, querySelectorAll: () => { queried = true; return []; }},
    });
    assert.equal(queried, false);
});
