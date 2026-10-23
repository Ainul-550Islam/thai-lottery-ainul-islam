import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

// Execute the actual module through narrow event/DOM ports. These do NOT
// measure layout, browser fragment scrolling, print output, or assistive tech.
class Element {
    constructor(id = '') { this.id = id; this.dataset = {}; this.value = ''; this.textContent = ''; this.attrs = new Map(); this.handlers = new Map(); this.classes = new Set(); this.focuses = 0;
        this.classList = { contains: key => this.classes.has(key), add: key => this.classes.add(key), remove: key => this.classes.delete(key), toggle: (key, force) => { const enabled = force === undefined ? !this.classes.has(key) : force; if (enabled) this.classes.add(key); else this.classes.delete(key); return enabled; } };
    }
    addEventListener(type, fn) { if (!this.handlers.has(type)) this.handlers.set(type, []); this.handlers.get(type).push(fn); }
    emit(type, options = {}) { const e = { target: this, preventDefault() { this.defaultPrevented = true; }, ...options }; for (const fn of this.handlers.get(type) || []) fn(e); return e; }
    setAttribute(key, value) { this.attrs.set(key, value); }
    getAttribute(key) { return this.attrs.get(key) ?? null; }
    removeAttribute(key) { this.attrs.delete(key); }
    closest() { return this.editable ? this : null; }
    focus() { this.focuses++; }
}
function terms({ mobile = false, widgets = true } = {}) {
    const input = new Element(), clear = new Element(), status = new Element(); status.textContent = 'ALL_LOCALIZED'; status.dataset = { none: 'NONE_LOCALIZED', matches: '__COUNT__ MATCHES_LOCALIZED' };
    const sections = [new Element('alpha'), new Element('beta')]; sections.forEach(section => { section.dataset.searchableText = section.id; });
    const links = sections.map(section => { const link = new Element(); link.dataset.sectionLink = section.id; return link; });
    const toggle = new Element(), list = new Element(); toggle.setAttribute('aria-expanded', 'true'); list.classList.add('is-open');
    const page = new Element();
    page.querySelector = selector => ({ '[data-terms-search]': input, '[data-clear-search]': clear, '[data-terms-search-status]': status, '[data-contents-toggle]': widgets ? toggle : null, '#terms-contents-list': widgets ? list : null }[selector] ?? null);
    page.querySelectorAll = selector => ({ '[data-term-section]': sections, '[data-section-link]': links }[selector] || []);
    const document = new Element(); Object.assign(document, { readyState: 'complete', querySelector: () => page });
    vm.runInNewContext(fs.readFileSync('resources/js/pages/terms.js', 'utf8'), { document, HTMLElement: Element, window: { innerWidth: mobile ? 390 : 1280, print() {} } });
    return { input, clear, status, sections, links, toggle, list, page, search(value) { input.value = value; input.emit('input'); } };
}
for (const mobile of [false, true]) {
    test(`Terms contents activation reveals its filtered-out destination (mobile=${mobile})`, () => {
        const t = terms({ mobile }); t.search('alpha'); assert.equal(t.sections[1].classList.contains('is-search-hidden'), true);
        const e = t.links[1].emit('click', { button: 0 });
        assert.equal(t.input.value, ''); assert.equal(t.status.textContent, 'ALL_LOCALIZED');
        for (const section of t.sections) assert.equal(section.classList.contains('is-search-hidden'), false);
        assert.equal(e.defaultPrevented, undefined, 'Keep native fragment navigation');
        assert.equal(t.input.focuses, 0, 'Do not redirect navigation focus to the search input');
        if (mobile) { assert.equal(t.list.classList.contains('is-open'), false); assert.equal(t.toggle.getAttribute('aria-expanded'), 'false'); }
    });
}
test('Terms activation of an already-visible destination preserves its current query', () => {
    const t = terms(); t.search('alpha'); t.links[0].emit('click', { button: 0 }); assert.equal(t.input.value, 'alpha'); assert.equal(t.status.textContent, '1 MATCHES_LOCALIZED'); assert.equal(t.sections[1].classList.contains('is-search-hidden'), true);
});
for (const options of [{ ctrlKey: true }, { metaKey: true }, { altKey: true }, { shiftKey: true }, { button: 1 }, { defaultPrevented: true }]) {
    test(`Terms cancelled or alternate contents activation preserves the current page filter ${JSON.stringify(options)}`, () => {
        const t = terms(); t.search('alpha'); t.links[1].emit('click', options); assert.equal(t.input.value, 'alpha'); assert.equal(t.sections[1].classList.contains('is-search-hidden'), true);
    });
}
test('Terms unknown contents target does not clear the search', () => {
    const t = terms(); t.search('alpha'); t.links[1].dataset.sectionLink = 'missing'; t.links[1].emit('click', { button: 0 }); assert.equal(t.input.value, 'alpha');
});
test('Terms filtered destination is revealed even without optional mobile contents widgets', () => {
    const t = terms({ widgets: false }); t.search('alpha'); t.links[1].emit('click', { button: 0 }); assert.equal(t.sections[1].classList.contains('is-search-hidden'), false); assert.equal(t.status.textContent, 'ALL_LOCALIZED');
});
test('Terms clear control still restores all sections, localized status and input focus', () => {
    const t = terms(); t.search('absent'); t.clear.emit('click'); assert.equal(t.status.textContent, 'ALL_LOCALIZED'); assert.equal(t.input.focuses, 1); assert.equal(t.input.value, ''); for (const section of t.sections) assert.equal(section.classList.contains('is-search-hidden'), false);
});
for (const options of [{ ctrlKey: true }, { metaKey: true }, { altKey: true }, { isComposing: true }, { defaultPrevented: true }]) {
    test(`Terms slash shortcut respects native or previously handled input ${JSON.stringify(options)}`, () => {
        const t = terms(); const e = t.page.emit('keydown', { key: '/', ...options }); assert.equal(t.input.focuses, 0); assert.equal(Boolean(e.defaultPrevented), Boolean(options.defaultPrevented));
    });
}
test('Terms unmodified slash still focuses search and prevents slash typing outside editors', () => {
    const t = terms(); const e = t.page.emit('keydown', { key: '/' }); assert.equal(e.defaultPrevented, true); assert.equal(t.input.focuses, 1);
});
test('Terms slash in an editable target is not intercepted', () => {
    const t = terms(), editor = new Element(); editor.editable = true; const e = t.page.emit('keydown', { key: '/', target: editor }); assert.equal(e.defaultPrevented, undefined); assert.equal(t.input.focuses, 0);
});
test('Terms print CSS source explicitly overrides search-hidden clauses without weakening screen filtering', () => {
    // Source cascade contract only: no CSS engine/print rendering is available.
    const css = fs.readFileSync('resources/css/pages/terms.css', 'utf8');
    assert.match(css, /\.terms-section\.is-search-hidden\s*\{\s*display:\s*none\s*;/);
    assert.match(css.slice(css.indexOf('@media print')), /\.terms-section\.is-search-hidden\s*\{\s*display:\s*block\s*!important\s*;\s*\}/);
});
