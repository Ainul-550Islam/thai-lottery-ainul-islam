import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

// Actual-source unit contracts with narrow DOM/event/clock doubles.
// No browser navigation, layout, physical device or crash closure is claimed.
const source = name => fs.readFileSync(`resources/js/${name}.js`, 'utf8');
function events() {
    const listeners = new Map();
    return {
        addEventListener(type, fn) { if (!listeners.has(type)) listeners.set(type, new Set()); listeners.get(type).add(fn); },
        removeEventListener(type, fn) { listeners.get(type)?.delete(fn); },
        emit(type, event = {}) { for (const fn of [...(listeners.get(type) || [])]) fn(event); },
    };
}
function media(legacy = false) {
    const target = events();
    const q = { matches: false, change(value) { this.matches = value; target.emit('change', { matches: value }); } };
    if (legacy) { q.addListener = fn => target.addEventListener('change', fn); q.removeListener = fn => target.removeEventListener('change', fn); }
    else { q.addEventListener = target.addEventListener; q.removeEventListener = target.removeEventListener; }
    return q;
}
function element(document, initial = []) {
    const attrs = new Map(), classes = new Set(initial);
    return Object.assign(events(), {
        getAttribute: key => attrs.get(key) ?? null, setAttribute: (key, value) => attrs.set(key, String(value)), removeAttribute: key => attrs.delete(key),
        focus() { document.activeElement = this; },
        classList: { contains: key => classes.has(key), add: key => classes.add(key), remove: key => classes.delete(key), toggle(key, value) { if (value) classes.add(key); else classes.delete(key); } },
    });
}
function menu(legacy = false) {
    const viewport = media(legacy), window = Object.assign(events(), { matchMedia: query => { assert.equal(query, '(min-width: 1280px)'); return viewport; } });
    const document = Object.assign(events(), { readyState: 'complete', defaultView: window, body: { style: { overflow: 'clip' } } });
    const open = element(document), close = element(document), drawer = element(document, ['translate-x-full']);
    document.getElementById = id => ({ 'btn-open-mobile-menu': open, 'btn-close-mobile-menu': close, 'mobile-menu-drawer': drawer }[id]);
    vm.runInNewContext(source('layout/mobile-menu'), { document, window });
    return { document, window, open, close, drawer, viewport };
}

test('drawer restores the previous scroll policy after duplicate opens and Escape', () => {
    const m = menu();
    m.open.emit('click'); m.open.emit('click');
    assert.equal(m.document.body.style.overflow, 'hidden');
    m.document.emit('keydown', { key: 'Escape' });
    assert.equal(m.document.body.style.overflow, 'clip');
    assert.equal(m.drawer.getAttribute('data-open'), 'false');
    assert.equal(m.document.activeElement, m.open);
});
for (const legacy of [false, true]) {
    test(`drawer exits its modal/scroll-lock state at desktop breakpoint (${legacy ? 'legacy' : 'modern'} media API)`, () => {
        const m = menu(legacy); m.open.emit('click'); m.viewport.change(true);
        assert.equal(m.drawer.getAttribute('data-open'), 'false');
        assert.equal(m.drawer.getAttribute('aria-hidden'), 'true');
        assert.equal(m.document.body.style.overflow, 'clip');
        assert.notEqual(m.document.activeElement, m.open, 'Do not focus the now-hidden mobile opener');
    });
}
test('pagehide closes the drawer without stealing focus, including BFCache navigation', () => {
    const m = menu(); m.open.emit('click'); m.window.emit('pagehide', { persisted: true });
    assert.equal(m.drawer.getAttribute('data-open'), 'false');
    assert.equal(m.document.body.style.overflow, 'clip');
    assert.notEqual(m.document.activeElement, m.open);
});
for (const kind of ['legacy', 'static', 'missing']) {
    test(`accessibility bootstrap tolerates ${kind} matchMedia support instead of aborting focus setup`, () => {
        const document = Object.assign(events(), { readyState: 'loading', documentElement: { classList: { add() {}, remove() {} } } });
        const q = kind === 'legacy' ? media(true) : { matches: false };
        const window = kind === 'missing' ? {} : { matchMedia: () => q };
        vm.runInNewContext(source('accessibility'), { document, window });
        assert.doesNotThrow(() => window.TLA11y.setupReducedMotionObserver());
        assert.doesNotThrow(() => window.TLA11y.setupFocusTraps());
    });
}
function countdown(reduced = false) {
    let now = Date.parse('2026-10-23T12:00:00Z'), nextId = 1;
    const intervals = new Map(), q = media(); q.matches = reduced;
    const output = { textContent: '' }, attrs = { 'data-target': '2026-10-23T12:05:17Z', 'data-reached-label': 'REACHED' };
    const root = { getAttribute: key => attrs[key], setAttribute: (key, value) => { attrs[key] = value; }, querySelector: selector => selector === '[data-countdown-output]' ? output : null };
    const window = Object.assign(events(), { matchMedia: () => q, setInterval(fn) { const id = nextId++; intervals.set(id, fn); return id; }, clearInterval: id => intervals.delete(id) });
    const document = { readyState: 'complete', querySelectorAll: () => [root] };
    vm.runInNewContext(source('home/countdown'), { document, window, Date: class extends Date { static now() { return now; } } });
    return { window, output, attrs, intervals, q, tick(seconds) { now += seconds * 1000; for (const fn of [...intervals.values()]) fn(); } };
}
test('reduced-motion timer advances even when browser throttling misses the exact minute boundary', () => {
    const c = countdown(true); c.tick(60); assert.equal(c.output.textContent, '00:04:17');
});
test('countdown honors live reduced-motion changes without restarting or multiplying timers', () => {
    const c = countdown(); c.tick(1); const text = c.output.textContent;
    c.q.change(true); c.tick(1); assert.equal(c.output.textContent, text);
    c.q.change(false); c.tick(1); assert.equal(c.output.textContent, '00:05:14');
    assert.equal(c.intervals.size, 1);
});
test('pagehide clears the countdown interval and BFCache pageshow resumes from the server instant only once', () => {
    const c = countdown(); c.window.emit('pagehide', { persisted: true }); assert.equal(c.intervals.size, 0);
    c.tick(60); c.window.emit('pageshow', { persisted: true }); assert.equal(c.output.textContent, '00:04:17');
    c.window.emit('pageshow', { persisted: true }); assert.equal(c.intervals.size, 1);
    c.tick(257); assert.equal(c.output.textContent, 'REACHED'); assert.equal(c.intervals.size, 0);
});

for (const pageName of ['fees', 'prize-verification']) {
    test(`${pageName} bounds timeout, suppresses duplicate requests and restores form state after abort`, async () => {
        const document = { readyState: 'complete' }, form = element(document), submit = { disabled: false };
        let handler, timeout, cleared = false, requests = 0, sent;
        form.dataset = {}; form.action = '/existing-server-form-action';
        form.elements = { category: { value: 'withdrawal' }, provider: { value: '' }, base_amount: { value: '00100.00' } };
        form.addEventListener = (type, fn) => { assert.equal(type, 'submit'); handler = fn; };
        form.querySelector = selector => selector === 'button[type="submit"]' ? submit : { value: 'unit-test-token' };
        const box = element(document); box.dataset = { networkError: 'LOCALIZED_RETRY' }; box.children = [];
        box.appendChild = child => box.children.push(child);
        document.createElement = () => ({ textContent: '' });
        const prefix = pageName === 'fees' ? 'fee-preview' : 'prize';
        const page = { querySelector: selector => selector === `[data-${prefix}-form]` ? form : selector === `[data-${prefix}-result]` ? box : null };
        document.querySelectorAll = () => [page];
        const context = vm.createContext({ document, AbortController, FormData: class {},
            setTimeout(fn, ms) { assert.equal(ms, 15000); timeout = fn; return 123; },
            clearTimeout(id) { assert.equal(id, 123); cleared = true; },
            fetch(url, options) {
                requests++; sent = options; assert.equal(url, form.action);
                return new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(new Error('PRIVATE_TIMEOUT_DETAIL'))));
            },
        });
        vm.runInContext(source('pages/public-response').replace(/export const /g, 'const '), context);
        vm.runInContext(source(`pages/${pageName}`).replace(/^\s*import[^;]+;\s*$/gm, ''), context);
        const event = { preventDefault() {} }, pending = handler(event);
        assert.equal(submit.disabled, true); assert.equal(form.getAttribute('aria-busy'), 'true');
        await handler(event); assert.equal(requests, 1); assert.equal(sent.credentials, 'same-origin');
        assert.equal(sent.headers.Accept, 'application/json');
        if (pageName === 'fees') {
            assert.equal(sent.headers['X-CSRF-TOKEN'], 'unit-test-token');
            assert.equal(JSON.parse(sent.body).base_amount, '00100.00');
        }
        timeout(); await pending;
        assert.equal(sent.signal.aborted, true); assert.equal(cleared, true);
        assert.equal(submit.disabled, false); assert.equal(form.getAttribute('aria-busy'), null);
        assert.equal(box.getAttribute('role'), 'alert');
        assert.equal(box.children.at(-1).textContent, 'LOCALIZED_RETRY');
        assert.doesNotMatch(box.children.at(-1).textContent, /PRIVATE_TIMEOUT_DETAIL/);
        const retry = handler(event); assert.equal(requests, 2); timeout(); await retry;
    });
}

test('retained Terms compatibility wrapper resolves its canonical implementation without a duplicate client', () => {
    const wrapper = new URL('../../resources/js/_unused/terms-portal.js', import.meta.url);
    const imported = fs.readFileSync(wrapper, 'utf8').match(/import\s+['"]([^'"]+)['"]/)[1];
    const resolved = new URL(imported, wrapper);
    assert.equal(fs.existsSync(resolved), true, `Compatibility import is missing: ${resolved.pathname}`);
    assert.equal(resolved.href, new URL('../../resources/js/pages/terms.js', import.meta.url).href);
});
