import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

// Actual-source contracts with narrow event/clock ports; not browser acceptance.
function events() {
    const handlers = new Map();
    return { addEventListener(type, fn) { if (!handlers.has(type)) handlers.set(type, new Set()); handlers.get(type).add(fn); },
        removeEventListener(type, fn) { handlers.get(type)?.delete(fn); },
        emit(type, value = {}) { for (const fn of [...(handlers.get(type) || [])]) fn(value); } };
}
function node(attrs = {}) {
    const values = new Map(Object.entries(attrs)), classes = new Set();
    return { ...events(), textContent: 'SERVER_TEXT', id: attrs.id,
        getAttribute: key => values.get(key) ?? null, setAttribute: (key, value) => values.set(key, String(value)), removeAttribute: key => values.delete(key),
        classList: { toggle(key, enabled) { if (enabled) classes.add(key); else classes.delete(key); }, contains: key => classes.has(key) } };
}
for (const name of ['public-next-pages', 'terms']) {
    test(`${name} exposes current-section semantics and clears the previous location`, () => {
        const sectionAttribute = name === 'terms' ? 'data-term-section' : 'data-content-section';
        const linkAttribute = name === 'terms' ? 'data-section-link' : 'data-content-link';
        const sections = [node({ id: 'first' }), node({ id: 'second' })];
        const links = [node(), node()];
        links.forEach((link, index) => { link.dataset = { contentLink: sections[index].id, sectionLink: sections[index].id }; });
        let notify;
        class Observer { constructor(callback) { notify = callback; } observe() {} }
        const page = Object.assign(events(), { querySelector: () => null,
            querySelectorAll: selector => selector === `[${sectionAttribute}]` ? sections : selector === `[${linkAttribute}]` ? links : [] });
        const document = { readyState: 'complete', addEventListener() {}, querySelector: () => page, querySelectorAll: () => [page] };
        vm.runInNewContext(fs.readFileSync(`resources/js/pages/${name}.js`, 'utf8'), { document, window: { IntersectionObserver: Observer }, IntersectionObserver: Observer });
        assert.equal(links[0].getAttribute('aria-current'), 'location');
        assert.equal(links[1].getAttribute('aria-current'), null);
        notify([{ isIntersecting: false, target: sections[0] }, { isIntersecting: true, target: sections[1] }]);
        assert.equal(links[0].getAttribute('aria-current'), null);
        assert.equal(links[1].getAttribute('aria-current'), 'location');
        assert.equal(links[1].classList.contains('is-active'), true);
    });
}
function playerClock({ reduced = false, target = '2026-10-23T12:05:17Z', dayLabel = 'LOCALIZED_DAYS' } = {}) {
    let now = Date.parse('2026-10-23T12:00:00Z'), id = 0;
    const timers = new Map(), q = Object.assign(events(), { matches: reduced });
    const element = node({ 'data-closes-at': target, 'data-closed-label': 'LOCALIZED_CLOSED', 'data-day-label': dayLabel, 'data-status-target': 'draw-countdown-status' });
    const status = node(); status.textContent = '';
    const emitted = [];
    const document = Object.assign(events(), { readyState: 'complete', hidden: false, querySelectorAll: () => [element], getElementById: key => key === 'draw-countdown-status' ? status : null, dispatchEvent: event => emitted.push(event) });
    const window = Object.assign(events(), { matchMedia: () => q, setInterval(fn) { timers.set(++id, fn); return id; }, clearInterval: key => timers.delete(key) });
    vm.runInNewContext(fs.readFileSync('resources/js/lottery/countdown.js', 'utf8'), { document, window, Date: class extends Date { static now() { return now; } }, CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } } });
    return { element, status, emitted, document, window, timers, tick(seconds) { now += seconds * 1000; for (const fn of [...timers.values()]) fn(); }, motion(value) { q.matches = value; q.emit('change', { matches: value }); } };
}
test('player reduced-motion countdown advances after a throttled off-boundary minute', () => {
    const c = playerClock({ reduced: true }); c.tick(60); assert.equal(c.element.textContent, '00 : 04 : 17');
});
test('player timer avoids per-second live announcements and localizes its one-time closed notice', () => {
    const c = playerClock(); assert.equal(c.element.getAttribute('aria-live'), 'off');
    c.tick(317); c.tick(10); assert.equal(c.element.textContent, 'LOCALIZED_CLOSED'); assert.equal(c.status.textContent, 'LOCALIZED_CLOSED');
    assert.equal(c.emitted.length, 1); assert.equal(c.emitted[0].type, 'lottery:betting-closed');
    assert.equal(c.emitted[0].detail.closesAt, '2026-10-23T12:05:17Z');
    assert.equal(c.element.getAttribute('data-countdown-state'), 'closed');
});
test('player countdown honors live reduced-motion changes', () => {
    const c = playerClock(); c.tick(1); const before = c.element.textContent;
    c.motion(true); c.tick(1); assert.equal(c.element.textContent, before);
    c.motion(false); c.tick(1); assert.equal(c.element.textContent, '00 : 05 : 14');
});
test('player countdown releases interval on pagehide and restores once from the original instant', () => {
    const c = playerClock(); c.window.emit('pagehide', { persisted: true }); assert.equal(c.timers.size, 0);
    c.tick(60); c.window.emit('pageshow', { persisted: true }); assert.equal(c.element.textContent, '00 : 04 : 17');
    c.window.emit('pageshow', { persisted: true }); assert.equal(c.timers.size, 1);
    c.tick(257); assert.equal(c.emitted.length, 1); assert.equal(c.timers.size, 0);
    c.window.emit('pageshow', { persisted: true }); assert.equal(c.emitted.length, 1); assert.equal(c.timers.size, 0);
});
test('player timer preserves unreadable server text and never constructs a target or close event', () => {
    const c = playerClock({ target: 'NOT_AN_INSTANT' }); assert.equal(c.element.textContent, 'SERVER_TEXT');
    assert.equal(c.element.getAttribute('data-countdown-state'), 'unreadable'); assert.equal(c.timers.size, 0); assert.equal(c.emitted.length, 0);
});
test('player timer uses the caller-provided day label instead of an English-only suffix', () => {
    const c = playerClock({ target: '2026-10-25T12:00:00Z' }); assert.equal(c.element.textContent, '2 LOCALIZED_DAYS 00 : 00 : 00');
});

test('compact public countdown preserves the caller-provided day label', () => {
    const output = node();
    const attrs = { 'data-target': '2026-10-25T12:00:00Z', 'data-day-label': 'LOCALIZED_DAYS' };
    const root = { getAttribute: key => attrs[key], setAttribute() {}, querySelector: selector => selector === '[data-countdown-output]' ? output : null };
    vm.runInNewContext(fs.readFileSync('resources/js/home/countdown.js', 'utf8'), {
        document: { readyState: 'complete', querySelectorAll: () => [root] },
        window: { setInterval() { return 1; }, clearInterval() {} },
        Date: class extends Date { static now() { return Date.parse('2026-10-23T12:00:00Z'); } },
    });
    assert.equal(output.textContent, '2 LOCALIZED_DAYS 00:00:00');
});

function responsePort() {
    const box = node(); box.dataset = { invalid: 'LOCALIZED_INVALID', malformed: 'LOCALIZED_MALFORMED' }; box.children = [];
    box.appendChild = child => box.children.push(child);
    Object.defineProperty(box, 'innerHTML', { set() { throw new Error('Untrusted HTML sink'); } });
    const context = vm.createContext({ document: { createElement: () => ({ textContent: '' }) } });
    vm.runInContext(fs.readFileSync('resources/js/pages/public-response.js', 'utf8').replace(/export const /g, 'var '), context);
    return { box, context };
}
test('public validation output is bounded and literal text rather than injected markup or exception data', async () => {
    const { box, context } = responsePort();
    const value = '<img src=x onerror=alert(1)>' + 'x'.repeat(400);
    const data = await context.readPublicResponse({ ok: false, status: 422, json: async () => ({ message: 'PRIVATE_EXCEPTION', errors: { value: [value, value, value, value, value, 7, {}] } }) }, box);
    assert.equal(data, null); assert.equal(box.getAttribute('role'), 'alert');
    assert.equal(box.children[0].textContent, 'LOCALIZED_INVALID ' + Array(4).fill(value.slice(0, 300)).join(' '));
    assert.doesNotMatch(box.children[0].textContent, /PRIVATE_EXCEPTION/);
});
test('public helper rejects malformed data shapes without inventing success values', async () => {
    for (const data of [null, [], 'not-an-object']) {
        const { box, context } = responsePort();
        assert.equal(await context.readPublicResponse({ ok: true, status: 200, json: async () => ({ success: true, data }) }, box), null);
        assert.equal(box.children[0].textContent, 'LOCALIZED_MALFORMED');
        assert.equal(box.getAttribute('role'), 'alert');
    }
});
