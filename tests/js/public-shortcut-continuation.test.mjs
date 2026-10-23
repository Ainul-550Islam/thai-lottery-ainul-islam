import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
// Actual shared source, narrow keyboard/DOM ports only; not native browser keys.
class Target {
    constructor() { this.handlers = new Map(); this.focuses = 0; }
    addEventListener(type, fn) { if (!this.handlers.has(type)) this.handlers.set(type, []); this.handlers.get(type).push(fn); }
    focus() { this.focuses++; }
    closest() { return this.editable ? this : null; }
}
function page() {
    const input = new Target(), root = new Target(), status = { textContent: 'LOCALIZED_ALL' };
    root.querySelector = selector => ({ '[data-local-search]': input, '[data-search-status]': status }[selector] ?? null);
    root.querySelectorAll = selector => selector === '[data-search-item]' ? [{ dataset: { searchText: 'alpha' }, classList: { toggle() {} } }] : [];
    vm.runInNewContext(fs.readFileSync('resources/js/pages/public-next-pages.js', 'utf8'), {
        document: { readyState: 'complete', querySelectorAll: () => [root] }, window: {}, HTMLElement: Target,
    });
    return { input, key(options = {}) { const e = { key: '/', target: root, preventDefault() { this.defaultPrevented = true; }, ...options }; for (const fn of root.handlers.get('keydown') || []) fn(e); return e; } };
}
for (const options of [{ ctrlKey: true }, { metaKey: true }, { altKey: true }, { isComposing: true }, { defaultPrevented: true }]) {
    test(`Public-page slash respects native or previously handled input ${JSON.stringify(options)}`, () => {
        const p = page(), e = p.key(options); assert.equal(p.input.focuses, 0); assert.equal(Boolean(e.defaultPrevented), Boolean(options.defaultPrevented));
    });
}
test('Public-page unmodified slash still focuses local search', () => {
    const p = page(), e = p.key(); assert.equal(p.input.focuses, 1); assert.equal(e.defaultPrevented, true);
});
test('Public-page slash inside an editable target is not intercepted', () => {
    const p = page(), editor = new Target(); editor.editable = true; const e = p.key({ target: editor }); assert.equal(p.input.focuses, 0); assert.equal(e.defaultPrevented, undefined);
});
