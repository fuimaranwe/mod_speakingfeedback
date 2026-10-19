// SPDX-License-Identifier: GPL-3.0-or-later
// Deterministic browser-API contract tests; real microphone checks are documented separately.
import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';

const source = (await readFile(new URL('../amd/src/recorder.js', import.meta.url), 'utf8'))
    .replace(/^import .*;\r?\n/gm, '').replace('export const init =', 'globalThis.init =');

class Element {
    disabled = false;
    hidden = false;
    textContent = '';
    listeners = new Map();
    data = null;
    addEventListener(name, callback) {
        this.listeners.set(name, callback);
    }
    async click(event = {}) {
        if (!this.disabled) {
            await this.listeners.get('click')?.(event);
        }
    }
    removeAttribute(name) {
        delete this[name];
    }
    querySelector() {
        return null;
    }
}

async function setup({preparation = 2, deny = false, failTranscribe = false} = {}) {
    const regions = Object.fromEntries(['timer', 'status', 'error', 'preview', 'remaining', 'history', 'pagination', 'result',
        'recordingindicator', 'timerlabel', 'starthelp']
        .map(name => [name, new Element()]));
    const buttons = Object.fromEntries(['record', 'stop', 'submit'].map(name => [name, new Element()]));
    const root = new Element();
    root.querySelector = selector => {
        const region = selector.match(/data-region="(.*?)"/);
        const action = selector.match(/data-action="(.*?)"/);
        return region ? regions[region[1]] : action ? buttons[action[1]] : null;
    };
    root.querySelectorAll = () => [];
    let now = 0;
    let intervalId = 0;
    let stopped = 0;
    let starts = 0;
    const intervals = new Map();
    const track = {readyState: 'live', stop() { stopped++; this.readyState = 'ended'; }, addEventListener() {}};
    const stream = {getTracks: () => [track], getAudioTracks: () => [track]};
    const actions = [];
    let persisted;
    const invoke = async action => {
        actions.push(action);
        if (action === 'begin') {
            persisted ??= {id: 1, status: 'draft', transcript: '', feedback: '', error: '', retryafter: 0};
            return persisted;
        }
        if (action === 'list') {
            return {total: persisted ? 1 : 0, attempts: persisted ? [persisted] : [], pagination: ''};
        }
        if (action === 'transcribe') {
            if (failTranscribe) {
                throw new Error('networkerror');
            }
            persisted.transcript = 'My response.';
        }
        if (action === 'feedback') {
            persisted.status = 'complete';
            persisted.feedback = 'Helpful feedback.';
            persisted.canretry = false;
        }
        return persisted;
    };
    class Recorder {
        static isTypeSupported() { return true; }
        state = 'inactive';
        mimeType = 'audio/webm';
        handlers = new Map();
        addEventListener(name, callback) { this.handlers.set(name, callback); }
        start() { starts++; this.state = 'recording'; }
        stop() {
            this.state = 'inactive';
            this.handlers.get('dataavailable')({data: new Blob(['recorded audio'], {type: 'audio/webm'})});
            this.handlers.get('stop')();
        }
    }
    const context = vm.createContext({
        document: {querySelector: () => root},
        window: {isSecureContext: true, MediaRecorder: Recorder, addEventListener() {}},
        navigator: {mediaDevices: {getUserMedia: async() => { if (deny) { throw new Error('denied'); } return stream; }}},
        MediaRecorder: Recorder, Blob, FormData, crypto: webcrypto, Uint8Array,
        URL: {createObjectURL: () => 'blob:preview', revokeObjectURL() {}},
        performance: {now: () => now},
        setInterval: callback => { intervals.set(++intervalId, callback); return intervalId; },
        clearInterval: id => intervals.delete(id),
        getStrings: async requests => requests.map(request => request.key),
        Ajax: {call: requests => requests.map(async request => JSON.stringify(await invoke(request.args.action)))},
        Templates: {
            renderForPromise: async(template, data) => ({html: JSON.stringify(data), js: ''}),
            replaceNodeContents: (element, html) => { element.data = html ? JSON.parse(html) : null; },
        },
        Config: {wwwroot: 'https://moodle.test', sesskey: 'test'},
        fetch: async() => {
            persisted.status = 'saved';
            persisted.canretry = true;
            return {ok: true, json: async() => ({...persisted})};
        },
    });
    vm.runInContext(source, context);
    await context.init({cmid: 1, preparation, recording: 10, maxbytes: 10000, maxattempts: 1, count: 0});
    return {regions, buttons, root, actions,
        starts: () => starts, stopped: () => stopped,
        advance: seconds => {
            now += seconds * 1000;
            [...intervals.values()].forEach(callback => callback());
        },
    };
}

test('permission denial never starts the preparation timer or recording', async() => {
    const ui = await setup({deny: true});
    await ui.buttons.record.click();
    ui.advance(20);
    assert.equal(ui.starts(), 0);
    assert.equal(ui.regions.error.textContent, 'microphoneerror');
    assert.equal(ui.buttons.record.disabled, false);
});

test('preparation precedes capture, deadline stops capture and releases microphone', async() => {
    const ui = await setup();
    assert.equal(ui.buttons.record.textContent, 'startpreparation');
    await ui.buttons.record.click();
    assert.equal(ui.starts(), 0);
    assert.equal(ui.regions.status.textContent, 'preparing');
    assert.equal(ui.regions.timerlabel.textContent, 'recordingstartsin');
    assert.equal(ui.regions.recordingindicator.hidden, true);
    ui.advance(2);
    assert.equal(ui.starts(), 1);
    assert.equal(ui.regions.status.textContent, 'recordingactive');
    assert.equal(ui.regions.recordingindicator.hidden, false);
    ui.advance(10);
    assert.equal(ui.stopped(), 1);
    assert.equal(ui.regions.preview.hidden, false);
    assert.equal(ui.regions.status.textContent, 'recorded');
    assert.equal(ui.buttons.record.textContent, 'recordagain');
    assert.equal(ui.regions.recordingindicator.hidden, true);
    assert.deepEqual(ui.actions, []);
});

test('zero preparation, early stop and submission run stages once and consume one attempt', async() => {
    const ui = await setup({preparation: 0});
    assert.equal(ui.buttons.record.textContent, 'startrecording');
    await ui.buttons.record.click();
    assert.equal(ui.starts(), 1);
    await ui.buttons.stop.click();
    await ui.buttons.submit.click();
    assert.equal(ui.actions.filter(action => action === 'begin').length, 1);
    assert.equal(ui.actions.filter(action => action === 'transcribe').length, 1);
    assert.equal(ui.actions.filter(action => action === 'feedback').length, 1);
    assert.equal(ui.regions.result.data.status, 'complete');
    assert.equal(ui.buttons.record.disabled, true);
});

test('a network failure after upload leaves an immediate saved-attempt retry control', async() => {
    const ui = await setup({preparation: 0, failTranscribe: true});
    await ui.buttons.record.click();
    await ui.buttons.stop.click();
    await ui.buttons.submit.click();
    assert.equal(ui.regions.error.textContent, 'networkerror');
    assert.equal(ui.regions.result.data.canretry, true);
    assert.equal(ui.regions.result.data.id, 1);
    assert.equal(ui.buttons.submit.hidden, true);
    assert.equal(ui.actions.includes('feedback'), false);
});
