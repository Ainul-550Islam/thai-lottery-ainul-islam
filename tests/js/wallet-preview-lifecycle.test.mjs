import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

// Source-level DOM port only: this is not browser/Laravel/runtime acceptance.
const sourcePath = process.env.WALLET_PREVIEW_SOURCE || fileURLToPath(new URL('../../resources/js/wallet-conversion.js', import.meta.url));
const source = fs.readFileSync(sourcePath, 'utf8');

function element(value = '') {
    const handlers = new Map();
    const attrs = new Map();
    return {
        value, textContent: '—', hidden: false, disabled: false,
        getAttribute(name) { return attrs.get(name) ?? null; },
        setAttribute(name, value) { attrs.set(name, String(value)); },
        addEventListener(name, handler) {
            const list = handlers.get(name) || [];
            list.push(handler);
            handlers.set(name, list);
        },
        emit(name) {
            for (const handler of handlers.get(name) || []) handler({ preventDefault() {} });
        },
        focus() {},
    };
}

function harness() {
    const requests = [];
    const timers = new Map();
    let nextTimer = 1;
    const form = element();
    const amount = element('100.00');
    const fee = element();
    const net = element();
    const rate = element();
    const note = element();
    const key = element('wc-12345678-1234-4234-8234-123456789abc');
    const fields = {
        '[data-wc-idempotency-key]': key,
        '[data-wc-quote-reference]': element('quote-fixture'),
        '[data-wc-amount]': amount,
        '[data-wc-preview-fee]': fee,
        '[data-wc-preview-net]': net,
        '[data-wc-preview-rate]': rate,
        '[data-wc-preview-note]': note,
        '[data-wc-submit]': element(),
        '[data-wc-quote]': element(),
        '[data-wc-amount-hint]': element(),
        '[data-wc-disabled-note]': element(),
        'input[name="_token"]': element('csrf-source-fixture'),
    };
    form.querySelector = (selector) => fields[selector] || null;
    for (const [name, value] of Object.entries({
        action: '/wallet/convert',
        'data-wc-preview-url': '/wallet/convert/preview',
        'data-wc-quote-url': '/wallet/convert/quote',
        'data-wc-direction': 'win_to_cash',
        'data-wc-currency': 'THB',
        'data-wc-min': '10.00',
        'data-wc-max': '1000.00',
        'data-wc-available': '500.00',
        'data-wc-unpriced-note': 'Server preview unavailable',
        'data-wc-amount-hint': 'Use the published range',
    })) form.setAttribute(name, value);
    const document = {
        readyState: 'complete',
        querySelector() { return null; },
        querySelectorAll(selector) { return selector === '[data-wc-conversion-form]' ? [form] : []; },
    };
    const window = {
        crypto: { randomUUID: () => '12345678-1234-4234-8234-123456789abc' },
        fetch(url, options) {
            return new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }));
        },
        setTimeout(fn) { const id = nextTimer++; timers.set(id, fn); return id; },
        clearTimeout(id) { timers.delete(id); },
        setInterval() { throw new Error('No expiry timer is expected in this fixture'); },
    };
    vm.runInNewContext(source, { window, document, Uint8Array, BigInt, Date, String, Math }, { filename: sourcePath });
    assert.equal(requests.length, 1, 'fixture must start one real production-code preview request');
    return {
        amount, fee, net, rate, note, requests,
        input(value) { amount.value = value; amount.emit('input'); },
        flushTimers() { const pending = [...timers.values()]; timers.clear(); for (const fn of pending) fn(); },
        async respond(index, feeAmount = '1.00', amountOut = '99.00') {
            requests[index].resolve({ ok: true, json: async () => ({
                fee_amount: feeAmount, amount_out: amountOut, percentage: '1', currency: 'THB', configured: true,
            }) });
            await new Promise(setImmediate);
        },
        async reject(index) { requests[index].reject(new Error('fixture network failure')); await new Promise(setImmediate); },
    };
}

function assertNoPreview(h) {
    assert.equal(h.fee.textContent, '—');
    assert.equal(h.net.textContent, '—');
    assert.equal(h.rate.textContent, '—');
}

test('input change immediately clears a previously displayed server preview', async () => {
    const h = harness();
    await h.respond(0);
    assert.equal(h.fee.textContent, '1.00 THB');
    h.input('');
    assertNoPreview(h);
});

test('cleared input cannot receive an older pending preview', async () => {
    const h = harness();
    h.input('');
    h.flushTimers();
    await h.respond(0);
    assert.equal(h.requests.length, 1);
    assertNoPreview(h);
});

test('invalid decimal input cannot receive an older pending preview', async () => {
    const h = harness();
    h.input('invalid-amount');
    h.flushTimers();
    await h.respond(0);
    assert.equal(h.requests.length, 1);
    assertNoPreview(h);
});

test('an old response is ignored during the debounce window for a new valid amount', async () => {
    const h = harness();
    h.input('200.00');
    await h.respond(0);
    assert.equal(h.requests.length, 1, 'the next request is still debounced');
    assertNoPreview(h);
});

test('below-minimum input invalidates the previous request without inventing a fee', async () => {
    const h = harness();
    h.input('5.00');
    h.flushTimers();
    await h.respond(0);
    assert.equal(h.requests.length, 1);
    assertNoPreview(h);
});

test('input above server-provided available balance cannot display the previous preview', async () => {
    const h = harness();
    h.input('501.00');
    h.flushTimers();
    await h.respond(0);
    assert.equal(h.requests.length, 1);
    assertNoPreview(h);
});

test('the current response wins and the existing preview transport is preserved', async () => {
    const h = harness();
    h.input('200.00');
    h.flushTimers();
    assert.equal(h.requests.length, 2);
    assert.equal(h.requests[1].url, '/wallet/convert/preview');
    assert.equal(h.requests[1].options.method, 'POST');
    assert.equal(h.requests[1].options.credentials, 'same-origin');
    assert.equal(h.requests[1].options.headers['X-CSRF-TOKEN'], 'csrf-source-fixture');
    assert.deepEqual(JSON.parse(h.requests[1].options.body), { direction: 'win_to_cash', amount: '200.00' });
    await h.respond(1, '2.00', '198.00');
    await h.respond(0, '1.00', '99.00');
    assert.equal(h.fee.textContent, '2.00 THB');
    assert.equal(h.net.textContent, '198.00 THB');
});

test('a current request failure shows no guessed fee or output amount', async () => {
    const h = harness();
    await h.reject(0);
    assertNoPreview(h);
    assert.equal(h.note.textContent, 'Server preview unavailable');
});
