// This file is part of Moodle - http://moodle.org/
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Recording lifecycle and recoverable two-stage feedback requests.
 * @module mod_speakingfeedback/recorder
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import Ajax from 'core/ajax';
import Templates from 'core/templates';
import {get_strings as getStrings} from 'core/str';
import Config from 'core/config';

/**
 * Initialise a recorder.
 * @param {Object} config Activity configuration.
 */
export const init = async(config) => {
    const root = document.querySelector(`[data-region="speakingfeedback"][data-cmid="${config.cmid}"]`);
    if (!root) {
        return;
    }
    const keys = ['ready', 'preparing', 'recording', 'recorded', 'uploading', 'transcribing', 'generating',
        'complete', 'unsupported', 'microphoneerror', 'interrupted', 'filetoolarge', 'networkerror',
        'recordagain', 'getfeedback', 'limitreached', 'remaining', 'nospeech',
        'startpreparation', 'startrecording', 'recordingstartsin', 'recordingactive', 'timeleft'];
    const values = await getStrings(keys.map(key => ({key, component: 'mod_speakingfeedback', param: '{$a}'})));
    const strings = Object.fromEntries(keys.map((key, i) => [key, values[i]]));
    const select = region => root.querySelector(`[data-region="${region}"]`);
    const record = root.querySelector('[data-action="record"]');
    const stop = root.querySelector('[data-action="stop"]');
    const submit = root.querySelector('[data-action="submit"]');
    const preview = select('preview');
    record.textContent = config.preparation ? strings.startpreparation : strings.startrecording;
    let stream;
    let recorder;
    let chunks = [];
    let blob;
    let objectUrl;
    let interval;
    let deadline;
    let state = 'ready';
    let token;
    let attemptId = 0;
    let busy = false;
    let interrupted = false;
    let bytes = 0;
    let submittedCount = config.count;
    const initiallyEnabled = !record.disabled;
    const supported = window.isSecureContext && navigator.mediaDevices?.getUserMedia && window.MediaRecorder;
    const status = key => {
        state = key;
        select('status').textContent = key === 'recording' ? strings.recordingactive : strings[key] || key;
        select('recordingindicator').hidden = key !== 'recording';
        select('timerlabel').textContent = key === 'preparing' ? strings.recordingstartsin : strings.timeleft;
        select('starthelp').hidden = ['preparing', 'recording', 'uploading', 'transcribing', 'generating'].includes(key);
    };
    const error = message => {
        select('error').textContent = message || '';
        select('error').hidden = !message;
    };
    const release = () => {
        clearInterval(interval);
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
    };
    const canRecord = () => supported && initiallyEnabled && (!config.maxattempts || submittedCount < config.maxattempts);
    const controls = () => {
        record.disabled = busy || !canRecord() || ['preparing', 'recording'].includes(state);
        submit.disabled = busy;
        root.querySelectorAll('[data-action="retry"]').forEach(button => {
            button.disabled = busy;
        });
    };
    const request = async(action, id = attemptId) => {
        const result = await Ajax.call([{
            methodname: 'mod_speakingfeedback_attempt',
            args: {cmid: config.cmid, action, attemptid: id, token: token || '', page: 0},
        }])[0];
        return JSON.parse(result);
    };
    const renderAttempt = async(attempt, target) => {
        const {html, js} = await Templates.renderForPromise('mod_speakingfeedback/attempt', attempt);
        Templates.replaceNodeContents(target, html, js);
    };
    const refreshHistory = async() => {
        const result = await request('list');
        submittedCount = result.total;
        const {html, js} = await Templates.renderForPromise('mod_speakingfeedback/history', result);
        Templates.replaceNodeContents(select('history'), html, js);
        Templates.replaceNodeContents(select('pagination'), result.pagination, '');
        if (config.maxattempts) {
            select('remaining').textContent = strings.remaining.replace('{$a}', Math.max(0, config.maxattempts - result.total));
        }
        controls();
    };
    const process = async(id) => {
        let attempt = await request('status', id);
        await renderAttempt(attempt, select('result'));
        if (!attempt.transcript && attempt.status !== 'nospeech') {
            status('transcribing');
            attempt = await request('transcribe', id);
        }
        if (attempt.transcript && attempt.status !== 'complete' && !attempt.error) {
            status('generating');
            attempt = await request('feedback', id);
        } else if (attempt.transcript && attempt.status !== 'complete' && attempt.retryafter <= Date.now() / 1000) {
            status('generating');
            attempt = await request('feedback', id);
        }
        await renderAttempt(attempt, select('result'));
        status(attempt.status === 'complete' ? 'complete' : attempt.status === 'nospeech' ? 'nospeech' : 'ready');
        error(attempt.error);
        await refreshHistory();
        select('history').querySelector(`[data-attemptid="${id}"]`)?.remove();
    };
    const displayTime = seconds => {
        const minutes = String(Math.floor(seconds / 60)).padStart(2, '0');
        select('timer').textContent = `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
    };
    const finish = () => {
        clearInterval(interval);
        stop.hidden = true;
        if (recorder && recorder.state !== 'inactive') {
            recorder.stop();
        }
    };
    const countdown = (seconds, callback) => {
        clearInterval(interval);
        deadline = performance.now() + seconds * 1000;
        displayTime(seconds);
        interval = setInterval(() => {
            const remaining = Math.max(0, Math.ceil((deadline - performance.now()) / 1000));
            displayTime(remaining);
            if (!remaining) {
                clearInterval(interval);
                callback();
            }
        }, 100);
    };
    const startRecording = () => {
        if (interrupted || !stream || !stream.getAudioTracks().some(track => track.readyState === 'live')) {
            release();
            status('ready');
            error(strings.interrupted);
            controls();
            return;
        }
        try {
            recorder.start(1000);
            status('recording');
            stop.hidden = false;
            countdown(config.recording, finish);
        } catch (e) {
            release();
            status('ready');
            error(strings.interrupted);
            controls();
        }
    };
    record.addEventListener('click', async() => {
        busy = true;
        controls();
        error('');
        release();
        blob = null;
        attemptId = 0;
        token = null;
        interrupted = false;
        bytes = 0;
        preview.hidden = true;
        preview.removeAttribute('src');
        submit.hidden = true;
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({audio: true, video: false});
            const mime = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus']
                .find(type => MediaRecorder.isTypeSupported(type));
            if (!mime) {
                throw new Error(strings.unsupported);
            }
            recorder = new MediaRecorder(stream, {mimeType: mime, audioBitsPerSecond: 64000});
            chunks = [];
            recorder.addEventListener('dataavailable', event => {
                if (event.data.size) {
                    chunks.push(event.data);
                    bytes += event.data.size;
                    if (bytes > config.maxbytes && recorder.state !== 'inactive') {
                        interrupted = true;
                        finish();
                    }
                }
            });
            recorder.addEventListener('error', () => {
                interrupted = true;
                finish();
            });
            stream.getTracks().forEach(track => track.addEventListener('ended', () => {
                interrupted = true;
                if (state === 'preparing') {
                    release();
                    status('ready');
                    error(strings.interrupted);
                    controls();
                } else {
                    finish();
                }
            }));
            recorder.addEventListener('stop', () => {
                release();
                if (interrupted || bytes > config.maxbytes || !bytes) {
                    status('ready');
                    error(bytes > config.maxbytes ? strings.filetoolarge : strings.interrupted);
                } else {
                    blob = new Blob(chunks, {type: recorder.mimeType});
                    objectUrl = URL.createObjectURL(blob);
                    preview.src = objectUrl;
                    preview.hidden = false;
                    submit.hidden = false;
                    record.textContent = strings.recordagain;
                    status('recorded');
                }
                controls();
            });
            if (config.preparation) {
                status('preparing');
                countdown(config.preparation, startRecording);
            } else {
                startRecording();
            }
        } catch (e) {
            release();
            status('ready');
            error(e.message === strings.unsupported ? strings.unsupported : strings.microphoneerror);
        } finally {
            busy = false;
            controls();
        }
    });
    stop.addEventListener('click', finish);
    submit.addEventListener('click', async() => {
        if (busy || !blob) {
            return;
        }
        busy = true;
        controls();
        error('');
        try {
            status('uploading');
            token = token || Array.from(crypto.getRandomValues(new Uint8Array(24)),
                value => value.toString(16).padStart(2, '0')).join('');
            const attempt = await request('begin');
            attemptId = attempt.id;
            const data = new FormData();
            data.append('id', config.cmid);
            data.append('attemptid', attemptId);
            data.append('sesskey', Config.sesskey);
            const extension = blob.type.includes('mp4') ? 'mp4' : blob.type.includes('ogg') ? 'ogg' : 'webm';
            data.append('audio', blob, `recording.${extension}`);
            const response = await fetch(`${Config.wwwroot}/mod/speakingfeedback/upload.php`, {
                method: 'POST', body: data, credentials: 'same-origin',
            });
            const result = await response.json();
            if (!response.ok || result.exception) {
                throw new Error(result.error || strings.networkerror);
            }
            submit.hidden = true;
            blob = null;
            // Show recovery controls before the first API request can fail.
            await renderAttempt(result, select('result'));
            await refreshHistory();
            await process(attemptId);
        } catch (e) {
            status('ready');
            error(e.message || strings.networkerror);
        } finally {
            busy = false;
            controls();
        }
    });
    root.addEventListener('click', async(event) => {
        const button = event.target.closest('[data-action="retry"]');
        if (!button || busy || ['preparing', 'recording'].includes(state)) {
            return;
        }
        busy = true;
        controls();
        error('');
        try {
            await process(Number(button.dataset.id));
        } catch (e) {
            status('ready');
            error(e.message || strings.networkerror);
        } finally {
            busy = false;
            controls();
        }
    });
    window.addEventListener('beforeunload', event => {
        if (blob || busy || ['preparing', 'recording'].includes(state)) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    window.addEventListener('pagehide', () => {
        interrupted = true;
        finish();
        release();
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
        }
    });
    if (!supported) {
        record.disabled = true;
        error(strings.unsupported);
    }
};
