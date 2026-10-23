import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const repoRoot = process.cwd();

function makeElement(tag = 'div', attrs = {}) {
    const attributes = new Map(Object.entries(attrs).map(([k, v]) => [k, String(v)]));
    const listeners = new Map();
    const children = [];
    const classes = new Set((attrs.class || '').split(/\s+/).filter(Boolean));

    let ownText = attrs.textContent || '';
    const el = {
        tagName: tag.toUpperCase(),
        id: attrs.id || '',
        value: attrs.value || '',
        hidden: Boolean(attrs.hidden),
        disabled: Boolean(attrs.disabled),
        dataset: { ...(attrs.dataset || {}) },
        elements: {},
        children,
        classList: {
            add: (...names) => names.forEach((n) => classes.add(n)),
            remove: (...names) => names.forEach((n) => classes.delete(n)),
            toggle: (n, force) => {
                const next = force === undefined ? !classes.has(n) : Boolean(force);
                if (next) classes.add(n);
                else classes.delete(n);
                return next;
            },
            contains: (n) => classes.has(n),
        },
        get textContent() { return ownText; },
        set textContent(value) { ownText = value; children.length = 0; },
        setAttribute(k, v) {
            attributes.set(k, String(v));
        },
        getAttribute(k) {
            return attributes.has(k) ? attributes.get(k) : null;
        },
        removeAttribute(k) {
            attributes.delete(k);
        },
        appendChild(child) {
            children.push(child);
            return child;
        },
        addEventListener(type, fn) {
            if (!listeners.has(type)) listeners.set(type, []);
            listeners.get(type).push(fn);
        },
        async dispatch(type, eventInit = {}) {
            const ev = {
                type,
                key: eventInit.key || '',
                target: eventInit.target || el,
                preventDefault() {},
            };
            for (const fn of listeners.get(type) || []) {
                await fn(ev);
            }
        },
        focus() {},
        querySelector: () => null,
        querySelectorAll: () => [],
    };
    return el;
}

test('fees.js treats HTTP 429, 422 validation payloads without success:false, and non-JSON 500s as alert errors rather than fake NOT_CONFIGURED previews', async () => {
    const raw = fs.readFileSync(path.join(repoRoot, 'resources/js/pages/fees.js'), 'utf8')
        .replace(/^\s*import[^;]+;\s*$/gm, '');

    const category = makeElement('select', { value: 'withdrawal' });
    const provider = makeElement('select', { value: '' });
    const baseAmount = makeElement('input', { value: '100.00' });
    const submitBtn = makeElement('button', { type: 'submit' });
    const resultBox = makeElement('div', { hidden: true, role: 'status' });
    const form = makeElement('form', { action: 'http://localhost/api/v1/fees/preview' });
    form.elements = { category, provider, base_amount: baseAmount };
    form.querySelector = (sel) => (sel === 'button[type="submit"]' ? submitBtn : null);

    const page = makeElement('div', { dataset: { nextPublicPage: 'fees' } });
    page.querySelector = (sel) => {
        if (sel === '[data-fee-preview-form]') return form;
        if (sel === '[data-fee-preview-result]') return resultBox;
        return null;
    };

    const domReadyHandlers = [];
    let mockFetchResponse = null;

    const context = vm.createContext({
        document: {
            readyState: 'loading',
            addEventListener(type, fn) {
                if (type === 'DOMContentLoaded') domReadyHandlers.push(fn);
            },
            querySelector: () => null,
            querySelectorAll(sel) {
                return sel === '[data-next-public-page="fees"]' ? [page] : [];
            },
            createElement(tag) {
                return makeElement(tag);
            },
        },
        AbortController, setTimeout, clearTimeout,
        fetch: async () => mockFetchResponse,
        FormData: class {},
        HTMLElement: class {},
    });

    vm.runInContext(fs.readFileSync(path.join(repoRoot, 'resources/js/pages/public-response.js'), 'utf8').replace(/export const /g, 'const '), context);
    vm.runInContext(raw, context);
    for (const fn of domReadyHandlers) fn();

    // Case 1: HTTP 429 rate-limit JSON without `success: false`
    mockFetchResponse = {
        ok: false,
        status: 429,
        json: async () => ({ message: 'Too Many Attempts.' }),
    };
    await form.dispatch('submit');
    const rendered429 = resultBox.children.map((c) => c.textContent).join(' | ');
    assert.equal(resultBox.getAttribute('role'), 'alert', 'HTTP 429 must switch preview result box to role="alert"');
    assert.doesNotMatch(rendered429, /NOT_CONFIGURED\s*·\s*NOT_CONFIGURED/, 'HTTP 429 must never render fake NOT_CONFIGURED success output');

    mockFetchResponse = { ok: false, status: 422, json: async () => ({ errors: { base_amount: ['Check amount.'] } }) };
    await form.dispatch('submit');
    assert.equal(resultBox.getAttribute('role'), 'alert');
    assert.match(resultBox.children.map(c => c.textContent).join(' '), /Check amount\./);

    // Case 2: HTTP 500 non-JSON HTML error page
    mockFetchResponse = {
        ok: false,
        status: 500,
        json: async () => {
            throw new SyntaxError('Unexpected token < in JSON');
        },
    };
    await form.dispatch('submit');
    const rendered500 = resultBox.children.map((c) => c.textContent).join(' | ');
    assert.equal(resultBox.getAttribute('role'), 'alert');
    assert.doesNotMatch(rendered500, /SyntaxError|Unexpected token/);
});

test('prize-verification.js handles HTTP 422 validation errors and 500 non-JSON responses with role="alert"', async () => {
    const raw = fs.readFileSync(path.join(repoRoot, 'resources/js/pages/prize-verification.js'), 'utf8')
        .replace(/^\s*import[^;]+;\s*$/gm, '');

    const submitBtn = makeElement('button', { type: 'submit' });
    const resultBox = makeElement('div', { hidden: true, role: 'status' });
    const form = makeElement('form', { action: 'http://localhost/prize-verification' });
    form.querySelector = (sel) => (sel === 'button[type="submit"]' ? submitBtn : null);

    const page = makeElement('div');
    page.querySelector = (sel) => {
        if (sel === '[data-prize-form]') return form;
        if (sel === '[data-prize-result]') return resultBox;
        return null;
    };

    const domReadyHandlers = [];
    let mockFetchResponse = null;

    const context = vm.createContext({
        document: {
            readyState: 'loading',
            addEventListener(type, fn) {
                if (type === 'DOMContentLoaded') domReadyHandlers.push(fn);
            },
            querySelector: () => null,
            querySelectorAll(sel) {
                return sel === '[data-prize-verifier]' ? [page] : [];
            },
            createElement(tag) {
                return makeElement(tag);
            },
        },
        AbortController, setTimeout, clearTimeout,
        fetch: async () => mockFetchResponse,
        FormData: class {},
    });

    vm.runInContext(fs.readFileSync(path.join(repoRoot, 'resources/js/pages/public-response.js'), 'utf8').replace(/export const /g, 'const '), context);
    vm.runInContext(raw, context);
    for (const fn of domReadyHandlers) fn();

    mockFetchResponse = {
        ok: false,
        status: 422,
        json: async () => ({
            message: 'The given data was invalid.',
            errors: { value: ['Ticket number must be 6 digits.'] },
        }),
    };
    await form.dispatch('submit');
    const rendered422 = resultBox.children.map((c) => c.textContent).join(' | ');
    assert.equal(resultBox.getAttribute('role'), 'alert', 'HTTP 422 must set role="alert" on prize verification result');
    assert.match(rendered422, /Ticket number must be 6 digits\./);
    mockFetchResponse = { ok: false, status: 500, json: async () => { throw new SyntaxError('private'); } };
    await form.dispatch('submit');
    assert.equal(resultBox.getAttribute('role'), 'alert');
    assert.doesNotMatch(resultBox.children.map(c => c.textContent).join(' '), /private/);
});

test('public-next-pages.js and terms.js preserve localized server-rendered search status when search input is cleared', async () => {
    // 1. public-next-pages.js
    const nextPagesSrc = fs.readFileSync(path.join(repoRoot, 'resources/js/pages/public-next-pages.js'), 'utf8');
    const searchInput = makeElement('input', { value: '' });
    const clearBtn = makeElement('button', { type: 'button' });
    const statusEl = makeElement('p', { textContent: 'แสดงทั้งหมด 2 รายการ' });
    const item1 = makeElement('section', { dataset: { searchText: 'ค่าธรรมเนียมการถอน' } });
    const item2 = makeElement('section', { dataset: { searchText: 'ค่าธรรมเนียมการฝาก' } });

    const nextPage = makeElement('div');
    nextPage.querySelector = (sel) => {
        if (sel === '[data-local-search]') return searchInput;
        if (sel === '[data-clear-search]') return clearBtn;
        if (sel === '[data-search-status]') return statusEl;
        return null;
    };
    nextPage.querySelectorAll = (sel) => (sel === '[data-search-item]' ? [item1, item2] : []);

    vm.runInNewContext(nextPagesSrc, {
        document: {
            readyState: 'complete',
            addEventListener() {},
            querySelector: () => null,
            querySelectorAll(sel) {
                return sel === '[data-next-public-page]' ? [nextPage] : [];
            },
        },
        window: { print() {} },
        HTMLElement: class {},
    });

    searchInput.value = 'ถอน';
    await searchInput.dispatch('input');
    await clearBtn.dispatch('click');
    assert.equal(statusEl.textContent, 'แสดงทั้งหมด 2 รายการ', 'Clearing local search must restore localized initial status text');

    // 2. terms.js
    const termsSrc = fs.readFileSync(path.join(repoRoot, 'resources/js/pages/terms.js'), 'utf8');
    const termsInput = makeElement('input', { value: '' });
    const termsClear = makeElement('button', { type: 'button' });
    const termsStatus = makeElement('p', { textContent: 'แสดงทั้งหมด 1 หมวด' });
    const termSec = makeElement('section', { id: 'scope', dataset: { searchableText: 'ขอบเขตการใช้งาน' } });

    const termsPage = makeElement('div');
    termsPage.querySelector = (sel) => {
        if (sel === '[data-terms-search]') return termsInput;
        if (sel === '[data-clear-search]') return termsClear;
        if (sel === '[data-terms-search-status]') return termsStatus;
        return null;
    };
    termsPage.querySelectorAll = (sel) => (sel === '[data-term-section]' ? [termSec] : []);

    vm.runInNewContext(termsSrc, {
        document: {
            readyState: 'complete',
            addEventListener() {},
            querySelector: (sel) => (sel === '[data-terms-page]' ? termsPage : null),
        },
        window: { innerWidth: 1280, print() {} },
        HTMLElement: class {},
    });

    termsInput.value = 'ขอบเขต';
    await termsInput.dispatch('input');
    await termsClear.dispatch('click');
    assert.equal(termsStatus.textContent, 'แสดงทั้งหมด 1 หมวด', 'Clearing Terms search must restore localized initial status text');
});
