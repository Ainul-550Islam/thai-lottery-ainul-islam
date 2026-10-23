import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { readPublicResponse } from '../../resources/js/pages/public-response.js';

// Minimal DOM port: executes actual source, not a browser/rendering substitute.
const notice = () => ({
    dataset: { unauthorized: 'SIGN_IN', forbidden: 'FORBIDDEN', expired: 'RELOAD', invalid: 'CHECK', rateLimited: 'WAIT', serverError: 'UNAVAILABLE', malformed: 'MALFORMED' },
    children: [], set textContent(v) { this.children = []; },
    setAttribute() {}, appendChild(node) { this.children.push(node); },
});

test('non-JSON auth/session/validation/rate-limit failures retain the HTTP-specific notice', async () => {
    const old = globalThis.document;
    globalThis.document = { createElement: () => ({ textContent: '' }) };
    try {
        for (const [status, expected] of [[401, 'SIGN_IN'], [403, 'FORBIDDEN'], [419, 'RELOAD'], [422, 'CHECK'], [429, 'WAIT'], [503, 'UNAVAILABLE']]) {
            const box = notice();
            const data = await readPublicResponse({ status, ok: false, json: async () => { throw Error('PRIVATE_HTML'); } }, box);
            assert.equal(data, null);
            assert.equal(box.children[0].textContent, expected, `HTTP ${status}`);
        }
        const box = notice();
        await readPublicResponse({ status: 200, ok: true, json: async () => { throw Error('PRIVATE_HTML'); } }, box);
        assert.equal(box.children[0].textContent, 'MALFORMED');
    } finally { globalThis.document = old; }
});

function countdown(reduced, target = '2026-10-23T12:05:17Z', grid = false) {
    let now = Date.parse('2026-10-23T12:00:00Z');
    const attrs = { 'data-target': target, 'data-reached-label': 'DRAW_TIME_REACHED' };
    const output = { textContent: 'SERVER_FALLBACK' };
    let callback;
    let cleared = false;
    const cells = Object.fromEntries(['days', 'hours', 'minutes', 'seconds'].map(k => [k, { textContent: '--' }]));
    const root = { getAttribute: name => attrs[name], setAttribute: (name, value) => { attrs[name] = value; },
        set textContent(value) { throw Error('Must not destroy countdown labels/grid'); },
        querySelector: selector => selector === '[data-countdown-output]' ? (grid ? null : output)
            : selector === '[data-countdown-status]' ? output
            : grid && selector.startsWith('#cd-') ? cells[selector.slice(4)] : null };
    const Clock = class extends Date { static now() { return now; } };
    vm.runInNewContext(fs.readFileSync('resources/js/home/countdown.js', 'utf8'), {
        Date: Clock, document: { readyState: 'complete', querySelectorAll: () => [root] },
        window: { matchMedia: () => ({ matches: reduced }), setInterval: fn => { callback = fn; return 1; }, clearInterval: () => { cleared = true; } },
    });
    return { output, attrs, cells, get cleared() { return cleared; }, tick(seconds) { now += seconds * 1000; callback?.(); } };
}

test('reduced-motion countdown renders immediately, updates at minute boundary, and reaches server instant', () => {
    const c = countdown(true);
    assert.equal(c.output.textContent, '00:05:17');
    assert.equal(c.attrs['data-countdown-state'], 'active');
    c.tick(1);
    assert.equal(c.output.textContent, '00:05:17');
    c.tick(16);
    assert.equal(c.output.textContent, '00:05:00');
    c.tick(300);
    assert.equal(c.output.textContent, 'DRAW_TIME_REACHED');
    assert.equal(c.cleared, true);
});

test('normal-motion countdown updates every second and expired targets never become active', () => {
    const normal = countdown(false);
    assert.equal(normal.output.textContent, '00:05:17');
    normal.tick(1);
    assert.equal(normal.output.textContent, '00:05:16');
    const past = countdown(true, '2026-10-23T11:59:00Z');
    assert.equal(past.output.textContent, 'DRAW_TIME_REACHED');
    assert.equal(past.attrs['data-countdown-state'], 'reached');
});

test('real home grid keeps localized unit nodes and updates numeric cells instead of replacing its root', () => {
    const c = countdown(false, '2026-10-23T12:05:17Z', true);
    assert.deepEqual(Object.values(c.cells).map(cell => cell.textContent), ['00', '00', '05', '17']);
    c.tick(317);
    assert.deepEqual(Object.values(c.cells).map(cell => cell.textContent), ['00', '00', '00', '00']);
    assert.equal(c.output.textContent, 'DRAW_TIME_REACHED');
});

test('mobile drawer participates in the existing modal focus trap and Escape restores the opener', () => {
    const listeners = [];
    const doc = { readyState: 'complete', body: { style: {} }, addEventListener: (type, fn) => { if (type === 'keydown') listeners.push(fn); } };
    const element = () => {
        const attrs = new Map(), classes = new Set(['translate-x-full']);
        return { click() { this.onClick(); }, addEventListener(type, fn) { if (type === 'click') this.onClick = fn; },
            getAttribute: k => attrs.get(k), setAttribute: (k, v) => attrs.set(k, v), removeAttribute: k => attrs.delete(k),
            focus() { doc.activeElement = this; }, classList: { contains: k => classes.has(k), add: k => classes.add(k), remove: k => classes.delete(k), toggle(k, v) { if (v) classes.add(k); else classes.delete(k); } } };
    };
    const open = element(), close = element(), last = element(), drawer = element();
    drawer.querySelectorAll = () => [close, last];
    doc.getElementById = id => ({ 'btn-open-mobile-menu': open, 'btn-close-mobile-menu': close, 'mobile-menu-drawer': drawer }[id]);
    doc.querySelector = () => drawer.getAttribute('data-open') === 'true' ? drawer : null;
    const window = {};
    vm.runInNewContext(fs.readFileSync('resources/js/layout/mobile-menu.js', 'utf8'), { document: doc });
    doc.readyState = 'loading'; // Register only the existing trap; no fake full-app bootstrap.
    vm.runInNewContext(fs.readFileSync('resources/js/accessibility.js', 'utf8'), { document: doc, window });
    window.TLA11y.setupFocusTraps();
    open.click();
    let prevented = false;
    listeners.forEach(fn => fn({ key: 'Tab', shiftKey: true, preventDefault() { prevented = true; } }));
    assert.equal(prevented, true);
    assert.equal(doc.activeElement, last);
    listeners.forEach(fn => fn({ key: 'Tab', shiftKey: false, preventDefault() {} }));
    assert.equal(doc.activeElement, close);
    listeners.forEach(fn => fn({ key: 'Escape' }));
    assert.equal(doc.activeElement, open);
    assert.equal(drawer.getAttribute('aria-hidden'), 'true');
    assert.equal(drawer.getAttribute('data-open'), 'false');
});
