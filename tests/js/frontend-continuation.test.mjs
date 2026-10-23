import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

// Actual source executed with narrow DOM/event/clock ports. These assertions
// are NOT browser layout, a rendered Blade tree, axe or assistive-tech acceptance.
const source = name => fs.readFileSync(`resources/js/${name}.js`, 'utf8');
function events() {
    const handlers = new Map();
    return {
        addEventListener(type, fn) { if (!handlers.has(type)) handlers.set(type, new Set()); handlers.get(type).add(fn); },
        removeEventListener(type, fn) { handlers.get(type)?.delete(fn); },
        emit(type, value = {}) { for (const fn of [...(handlers.get(type) || [])]) fn(value); },
    };
}
function focusTrap(kinds = ['normal', 'normal']) {
    const document = Object.assign(events(), { readyState: 'loading' });
    const controls = kinds.map(kind => ({
        kind, disabled: kind === 'disabled', tabIndex: kind === 'negative-tabindex' ? -1 : 0,
        matches(selector) { assert.equal(selector, ':disabled'); return ['disabled', 'disabled-fieldset'].includes(kind); },
        closest() { return ['hidden-ancestor', 'inert-ancestor'].includes(kind) ? {} : null; },
        getClientRects() { return ['display-none', 'hidden-input'].includes(kind) ? [] : [{}]; },
        focus() { if (kind === 'normal') document.activeElement = this; },
    }));
    const attrs = new Map();
    const modal = {
        querySelectorAll: () => controls, contains: node => controls.includes(node) || node === modal,
        getAttribute: key => attrs.get(key) ?? null, setAttribute: (key, value) => attrs.set(key, value),
        hasAttribute: key => attrs.has(key), focus() { document.activeElement = this; },
    };
    const window = { getComputedStyle: element => ({ visibility: element.kind === 'visibility-hidden' ? 'hidden' : 'visible' }) };
    document.querySelector = () => modal;
    vm.runInNewContext(source('accessibility'), { window, document });
    window.TLA11y.setupFocusTraps();
    return { document, controls, modal, tab(shiftKey = false) {
        const event = { key: 'Tab', shiftKey, prevented: false, preventDefault() { this.prevented = true; } };
        document.emit('keydown', event); return event;
    } };
}
for (const kind of ['disabled', 'disabled-fieldset', 'negative-tabindex', 'hidden-input', 'hidden-ancestor', 'inert-ancestor', 'display-none', 'visibility-hidden']) {
    test(`modal Tab wrap excludes a non-tabbable final candidate (${kind})`, () => {
        const c = focusTrap(['normal', 'normal', kind]);
        c.document.activeElement = c.controls[1];
        const event = c.tab();
        assert.equal(event.prevented, true);
        assert.equal(c.document.activeElement, c.controls[0]);
    });
}
for (const shiftKey of [false, true]) {
    test(`modal recovers Tab focus that begins outside the active dialog (shift=${shiftKey})`, () => {
        const c = focusTrap(); c.document.activeElement = { outside: true };
        const event = c.tab(shiftKey);
        assert.equal(event.prevented, true);
        assert.equal(c.document.activeElement, c.controls[shiftKey ? 1 : 0]);
    });
}
test('modal with no available controls retains keyboard focus on its container', () => {
    const c = focusTrap([]); c.document.activeElement = { outside: true };
    const event = c.tab(); assert.equal(event.prevented, true);
    assert.equal(c.document.activeElement, c.modal); assert.equal(c.modal.getAttribute('tabindex'), '-1');
});
test('modal normal interior Tab is not intercepted', () => {
    const c = focusTrap(['normal', 'normal', 'normal']); c.document.activeElement = c.controls[1];
    assert.equal(c.tab().prevented, false); assert.equal(c.document.activeElement, c.controls[1]);
});

function accordion() {
    const document = { readyState: 'complete' }, window = {};
    const input = Object.assign(events(), { value: '' }), status = { textContent: 'SERVER_LOCALIZED_SEARCH_HINT' };
    const rows = ['keep alpha', 'omit beta', 'keep gamma'].map(text => ({
        dataset: { searchText: text }, hidden: false, classList: { toggle() {} },
    }));
    const panels = rows.map((row, i) => ({ id: `panel-${i}`, hidden: true }));
    const buttons = rows.map((row, i) => Object.assign(events(), {
        disabled: false, getAttribute: key => key === 'aria-controls' ? `panel-${i}` : 'false',
        closest: () => row.hidden ? row : null,
        focus() { if (!row.hidden) document.activeElement = this; },
    }));
    const page = Object.assign(events(), {
        querySelector: selector => selector === '[data-local-search]' ? input : selector === '[data-search-status]' ? status : null,
        querySelectorAll: selector => ({ '[data-search-item]': rows, '[data-accordion-button]': buttons, '[id]': panels }[selector] || []),
    });
    document.querySelectorAll = () => [page];
    document.querySelector = () => ({ content: "LOCALIZED __COUNT__" });
    vm.runInNewContext(source('pages/public-next-pages'), { document, window });
    return { document, input, rows, buttons, search(value) { input.value = value; input.emit('input'); }, arrow(index, key) {
        const event = { key, prevented: false, preventDefault() { this.prevented = true; } };
        document.activeElement = buttons[index]; buttons[index].emit('keydown', event); return event;
    } };
}
test('public accordion ArrowDown skips a search-hidden middle item', () => {
    const c = accordion(); c.search('keep'); assert.equal(c.rows[1].hidden, true);
    assert.equal(c.arrow(0, 'ArrowDown').prevented, true); assert.equal(c.document.activeElement, c.buttons[2]);
});
test('public accordion ArrowUp skips a search-hidden middle item', () => {
    const c = accordion(); c.search('keep');
    assert.equal(c.arrow(2, 'ArrowUp').prevented, true); assert.equal(c.document.activeElement, c.buttons[0]);
});
test('public accordion with one search match wraps to itself rather than an unfocusable hidden row', () => {
    const c = accordion(); c.search('alpha');
    let attempts = 0; const focus = c.buttons[1].focus; c.buttons[1].focus = () => { attempts++; focus(); };
    c.arrow(0, 'ArrowDown'); assert.equal(attempts, 0); assert.equal(c.document.activeElement, c.buttons[0]);
});
test('public accordion retains ordinary unfiltered arrow navigation', () => {
    const c = accordion(); c.arrow(0, 'ArrowDown'); assert.equal(c.document.activeElement, c.buttons[1]);
    c.arrow(1, 'ArrowUp'); assert.equal(c.document.activeElement, c.buttons[0]);
});

test('public countdown does not repeat its reached announcement on repeated persisted pageshow', () => {
    const intervals = new Map(), output = { textContent: 'SERVER_TIMER' }; let now = Date.parse('2026-10-23T12:00:00Z'), writes = 0;
    const status = { set textContent(value) { assert.equal(value, 'LOCALIZED_REACHED'); writes++; } };
    const attrs = { 'data-target': '2026-10-23T12:00:02Z', 'data-reached-label': 'LOCALIZED_REACHED' };
    const root = { getAttribute: key => attrs[key], setAttribute: (key, value) => { attrs[key] = value; }, querySelector: selector => selector === '[data-countdown-output]' ? output : selector === '[data-countdown-status]' ? status : null };
    const window = Object.assign(events(), { setInterval(fn) { intervals.set(1, fn); return 1; }, clearInterval(id) { intervals.delete(id); } });
    const document = { readyState: 'complete', querySelectorAll: () => [root] };
    vm.runInNewContext(source('home/countdown'), { document, window, Date: class extends Date { static now() { return now; } } });
    assert.equal(intervals.size, 1); now += 3000; for (const fn of [...intervals.values()]) fn();
    assert.equal(writes, 1); assert.equal(intervals.size, 0);
    window.emit('pagehide', { persisted: true }); window.emit('pageshow', { persisted: true }); window.emit('pageshow', { persisted: true });
    assert.equal(writes, 1); assert.equal(output.textContent, 'LOCALIZED_REACHED'); assert.equal(intervals.size, 0);
});
