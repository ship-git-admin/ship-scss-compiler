'use strict';
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync(require('path').join(__dirname, '../assets/admin-watch.js'), 'utf8');
async function scenario(replies, enabled = true) {
    const label = {}, calls = [], timers = new Map(), events = {};
    let id = 0;
    const window = { addEventListener: (name, fn) => { events[name] = fn; } };
    if (enabled) window.shipScssWatch = { ajax: '/admin-ajax.php', probe: '/admin-watch.php', nonce: 'nonce' };
    const context = { window, document: { querySelector: () => label }, AbortController, URLSearchParams, Date,
        setTimeout: (fn, delay) => { timers.set(++id, { fn, delay }); return id; }, clearTimeout: n => timers.delete(n),
        fetch: async (url, options) => {
            calls.push({ url, options });
            const data = replies.shift();
            if (!data) throw new Error('Unexpected request');
            return { status: data.status || 200, ok: !data.status || data.status < 400, json: async () => data.body };
        } };
    vm.runInNewContext(source, context);
    const flush = () => new Promise(resolve => setImmediate(resolve));
    await flush();
    return { label, calls, timers, events, flush };
}
(async () => {
    let test = await scenario([{ body: { success: true, data: { token: 'secret' } } }, { body: { status: 'compiled' } }]);
    assert.strictEqual(test.calls.length, 2);
    assert(test.label.textContent.includes('コンパイル完了'));
    assert.strictEqual(test.calls[1].url, '/admin-watch.php');
    assert.strictEqual(test.calls[1].options.body.get('token'), 'secret');
    assert.strictEqual(test.calls[1].options.credentials, 'same-origin');
    assert(test.calls.every(call => call.options.method === 'POST' && !call.url.includes('secret')));
    test.events.pagehide();
    const pending = [...test.timers.values()].find(t => t.delay === 2000);
    await pending.fn(); await test.flush();
    assert.strictEqual(test.calls.length, 2);
    console.log('ok: admin polling uses authenticated POST, displays compilation and stops after pagehide');
    test = await scenario([{ status: 403, body: { success: false } }]);
    assert.strictEqual(test.calls.length, 1);
    assert(test.label.textContent.includes('エラー'));
    assert([...test.timers.values()].some(t => t.delay === 4000));
    console.log('ok: denied lease does not probe and retries with backoff');
    test = await scenario([{ body: { success: true, data: { token: 'secret' } } }, { status: 410, body: { status: 'expired' } }]);
    assert(test.label.textContent.includes('エラー'));
    console.log('ok: expired probe is visible as an error, not successful monitoring');
    test = await scenario([], false);
    assert.strictEqual(test.calls.length, 0);
    console.log('ok: no polling without admin configuration');
})().catch(error => { console.error(error); process.exitCode = 1; });
