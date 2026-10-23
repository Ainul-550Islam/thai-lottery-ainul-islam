import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
// Actual form/helper source through narrow DOM/event/transport ports. All
// responses are isolated test fixtures; no network, real account or money action.
// This is NOT browser navigation/BFCache/server cancellation acceptance.
function deferred() { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; }
function events() { const handlers = new Map(); return { addEventListener(type, fn) { if (!handlers.has(type)) handlers.set(type, []); handlers.get(type).push(fn); }, emit(type, event = {}) { for (const fn of handlers.get(type) || []) fn(event); } }; }
function element() {
    const attrs = new Map(); let text = '';
    const node = { ...events(), dataset: {}, children: [], hidden: true, setAttribute: (key, value) => attrs.set(key, String(value)), getAttribute: key => attrs.get(key) ?? null, removeAttribute: key => attrs.delete(key), appendChild(child) { this.children.push(child); } };
    Object.defineProperty(node, 'textContent', { get() { return text + this.children.map(child => child.textContent).join(''); }, set(value) { text = value; this.children = []; } }); return node;
}
function fixture(name, honorAbort = false) {
    const window = events(), form = element(), box = element(), submit = { disabled: false }, timers = new Map(), requests = [];
    form.action = '/existing-server-form-action'; form.elements = { category: { value: 'withdrawal' }, provider: { value: '' }, base_amount: { value: '00100.00' } };
    form.querySelector = selector => selector === 'button[type="submit"]' ? submit : { value: 'unit-test-token' };
    box.dataset = { pending: 'LOCALIZED_PENDING', networkError: 'LOCALIZED_NETWORK', malformed: 'LOCALIZED_MALFORMED', invalid: 'LOCALIZED_INVALID' };
    const prefix = name === 'fees' ? 'fee-preview' : 'prize';
    const page = { querySelector: selector => selector === `[data-${prefix}-form]` ? form : selector === `[data-${prefix}-result]` ? box : null };
    const document = { readyState: 'complete', querySelectorAll: () => [page], createElement: () => ({ textContent: '' }) };
    let nextTimer = 0, handler;
    form.addEventListener = (type, fn) => { assert.equal(type, 'submit'); handler = fn; };
    const context = vm.createContext({ document, window, AbortController, FormData: class {},
        setTimeout(fn, ms) { assert.equal(ms, 15000); const id = ++nextTimer; timers.set(id, fn); return id; }, clearTimeout(id) { timers.delete(id); },
        fetch(url, options) {
            assert.equal(url, form.action); assert.equal(options.method, 'POST'); assert.equal(options.credentials, 'same-origin');
            assert.equal(options.headers.Accept, 'application/json');
            if (name === 'fees') { assert.equal(options.headers['X-CSRF-TOKEN'], 'unit-test-token'); assert.equal(JSON.parse(options.body).base_amount, '00100.00'); }
            const r = { options, headers: deferred(), body: deferred(), started: deferred(), reads: 0 }; requests.push(r);
            options.signal.addEventListener('abort', () => { if (honorAbort) { r.headers.reject(new Error('PRIVATE_TRANSPORT_ABORT')); if (r.reads) r.body.reject(new Error('PRIVATE_BODY_ABORT')); } });
            return r.headers.promise;
        },
    });
    vm.runInContext(fs.readFileSync('resources/js/pages/public-response.js', 'utf8').replace(/export const /g, 'const '), context);
    vm.runInContext(fs.readFileSync(`resources/js/pages/${name}.js`, 'utf8').replace(/^\s*import[^;]+;\s*$/gm, ''), context);
    const data = name === 'fees' ? { fee_display: 'SERVER_FEE', state: 'SERVER_STATE', base_amount: '00100.00', currency: 'SERVER_CURRENCY', rule_version: 'SERVER_RULE' } : { status: 'SERVER_STATUS', reason: 'SERVER_REASON' };
    return { window, form, box, submit, timers, requests, data, send() { return handler({ preventDefault() {} }); },
        async headers(index = 0, status = 200) { const r = requests[index]; r.headers.resolve({ ok: status < 400, status, json() { r.reads++; r.started.resolve(); return r.body.promise; } }); await r.started.promise; assert.equal(r.reads, 1); },
        body(index = 0, value = { success: true, data }) { requests[index].body.resolve(value); },
        cleanup() { for (const r of requests) { r.headers.reject(new Error('TEST_CLEANUP')); if (r.reads) r.body.reject(new Error('TEST_CLEANUP')); } },
    };
}
for (const name of ['fees', 'prize-verification']) {
    test(`${name}: pagehide aborts in-flight fetch and immediately releases timeout/busy controls`, async () => {
        const p = fixture(name, true), job = p.send();
        try { assert.equal(p.timers.size, 1); p.window.emit('pagehide', { persisted: true }); assert.equal(p.requests[0].options.signal.aborted, true); assert.equal(p.timers.size, 0); assert.equal(p.form.getAttribute('aria-busy'), null); assert.equal(p.submit.disabled, false); assert.equal(p.box.hidden, true); assert.equal(p.box.textContent, ''); }
        finally { p.cleanup(); await job; }
    });
    test(`${name}: late successful JSON cannot render after pagehide`, async () => {
        const p = fixture(name), job = p.send(); await p.headers(); p.window.emit('pagehide', { persisted: true }); p.body(); await job;
        assert.equal(p.box.hidden, true); assert.equal(p.box.textContent, ''); assert.equal(p.form.getAttribute('aria-busy'), null); assert.equal(p.timers.size, 0);
    });
    test(`${name}: late JSON parse failure cannot render an alert after pagehide`, async () => {
        const p = fixture(name), job = p.send(); await p.headers(); p.window.emit('pagehide'); p.requests[0].body.reject(new Error('PRIVATE_JSON_DETAIL')); await job;
        assert.equal(p.box.hidden, true); assert.equal(p.box.textContent, ''); assert.equal(p.timers.size, 0);
    });
    test(`${name}: late transport failure cannot render a network alert after pagehide`, async () => {
        const p = fixture(name), job = p.send(); p.window.emit('pagehide'); p.requests[0].headers.reject(new Error('PRIVATE_FETCH_DETAIL')); await job;
        assert.equal(p.box.hidden, true); assert.equal(p.box.textContent, ''); assert.equal(p.submit.disabled, false);
    });
    test(`${name}: restored-page retry is isolated from an abandoned old response/finally`, async () => {
        const p = fixture(name), old = p.send(); await p.headers(); p.window.emit('pagehide', { persisted: true }); p.window.emit('pageshow', { persisted: true }); const next = p.send();
        try { assert.equal(p.requests.length, 2); await p.headers(1); p.body(0); await old; assert.equal(p.form.getAttribute('aria-busy'), 'true'); assert.equal(p.submit.disabled, true); assert.equal(p.box.textContent, 'LOCALIZED_PENDING'); assert.equal(p.timers.size, 1); p.body(1); await next; assert.equal(p.submit.disabled, false); assert.equal(p.form.getAttribute('aria-busy'), null); assert.equal(p.timers.size, 0); assert.match(p.box.textContent, /SERVER_/); }
        finally { p.cleanup(); await old; await next; }
    });
    test(`${name}: idle pagehide preserves an already-completed server response`, async () => {
        const p = fixture(name), job = p.send(); await p.headers(); p.body(); await job; const before = p.box.textContent; p.window.emit('pagehide');
        assert.equal(p.requests[0].options.signal.aborted, false); assert.equal(p.box.hidden, false); assert.equal(p.box.textContent, before); assert.equal(p.submit.disabled, false); assert.equal(p.timers.size, 0);
    });
    test(`${name}: timeout during JSON body decoding retains the localized retry error`, async () => {
        const p = fixture(name, true), job = p.send(); await p.headers(); for (const fn of [...p.timers.values()]) fn(); await job;
        assert.equal(p.box.hidden, false); assert.equal(p.box.getAttribute('role'), 'alert'); assert.equal(p.box.textContent, 'LOCALIZED_NETWORK'); assert.equal(p.submit.disabled, false); assert.equal(p.timers.size, 0);
    });
    test(`${name}: genuine malformed JSON without abort remains the localized malformed error`, async () => {
        const p = fixture(name), job = p.send(); await p.headers(); p.requests[0].body.reject(new Error('PRIVATE_JSON_DETAIL')); await job;
        assert.equal(p.box.hidden, false); assert.equal(p.box.getAttribute('role'), 'alert'); assert.equal(p.box.textContent, 'LOCALIZED_MALFORMED'); assert.equal(p.submit.disabled, false); assert.equal(p.timers.size, 0);
    });
}
