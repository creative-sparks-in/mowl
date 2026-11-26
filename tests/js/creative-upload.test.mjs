import assert from 'node:assert/strict';
import { before, test } from 'node:test';

let uploader;
let requests;
let plan;

before(async () => {
    globalThis.window = { Alpine: { data: (name, factory) => { uploader = factory; } } };
    globalThis.document = { addEventListener: (event, callback) => callback(), createElement: () => ({}) };
    globalThis.XMLHttpRequest = class {
        upload = {};

        open(method, url) { this.url = url; }

        setRequestHeader() {}

        send() {
            requests.push(this.url.includes('comp=blocklist') ? 'list' : 'block');
            const step = plan(requests.length, requests.at(-1));
            setTimeout(() => {
                if (this.aborted) return;
                step?.before?.();
                if (step?.fail) return this.onerror();
                this.status = 201;
                this.onload();
            }, 5);
        }

        abort() { this.aborted = true; this.onabort(); }
    };
    await import('../../resources/js/creative-upload.js');
});

async function upload(makePlan) {
    requests = [];
    const calls = [];
    const ui = uploader({
        requestUpload: async () => ({ id: 7, upload: { url: 'https://account.blob.core.windows.net/creatives/a/original.png?sig=1', headers: {}, blocks: true }, poster: null }),
        finalizeUpload: async () => { calls.push('finalize'); return { ok: true }; },
        cancelUpload: async () => { calls.push('cancel'); },
    }, null);
    plan = makePlan(() => ui.cancel(ui.uploads[0]));
    ui.add([new File([new Uint8Array(20 * 1024 * 1024)], 'a.png', { type: 'image/png' })]);
    await new Promise((resolve) => setTimeout(resolve, 2500));

    return { requests, calls, state: ui.uploads[0].state };
}

test('a 20 MB file goes up in three blocks, a block list, then finalize', async () => {
    assert.deepEqual(await upload(() => () => null), { requests: ['block', 'block', 'block', 'list'], calls: ['finalize'], state: 'done' });
});

test('cancelling during a retry backoff sends nothing more', async () => {
    const result = await upload((cancel) => (n) => (n === 1 ? (setTimeout(cancel, 300), { fail: true }) : null));
    assert.deepEqual(result, { requests: ['block'], calls: ['cancel'], state: 'cancelled' });
});

test('cancelling between blocks, before the block list or before finalize never commits', async () => {
    assert.deepEqual((await upload((cancel) => (n) => (n === 1 ? { before: cancel } : null))).requests, ['block']);
    assert.deepEqual((await upload((cancel) => (n) => (n === 3 ? { before: cancel } : null))).requests, ['block', 'block', 'block']);
    assert.deepEqual((await upload((cancel) => (n, kind) => (kind === 'list' ? { before: cancel } : null))).calls, ['cancel']);
});
