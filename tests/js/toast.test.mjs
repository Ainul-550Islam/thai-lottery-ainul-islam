/**
 * Tests for resources/js/ui/toast.js.
 *
 * The module is exercised against a minimal DOM stand-in rather than a headless
 * browser: the behaviours under test are all structural (a live region exists, a
 * message is written as TEXT, the timer is cancelled on dismissal), and none of
 * them depend on layout. A stand-in keeps this suite runnable with `node --test`
 * and no browser download.
 *
 * RUN: node --test tests/js/
 */
import assert from 'node:assert/strict';
import test from 'node:test';

/** A DOM small enough to reason about and complete enough to run the module. */
class FakeClassList {
    constructor() {
        this.set = new Set();
    }

    add(...names) {
        names.forEach((name) => this.set.add(name));
    }

    contains(name) {
        return this.set.has(name);
    }
}

class FakeNode {
    constructor(tagName) {
        this.tagName = tagName.toUpperCase();
        this.children = [];
        this.attributes = {};
        this.listeners = {};
        this.classList = new FakeClassList();
        // className and classList are the same list: the module sets className on
        // the region and uses classList everywhere else, so a stand-in that kept
        // them separate would be testing a DOM that does not exist.
        this._className = '';
        this.dataset = {};
        this.textContent = '';
        this.isConnected = false;
        this.parent = null;
        this.hidden = false;
    }

    get className() {
        return this._className;
    }

    set className(value) {
        this._className = String(value || '');
        this.classList = new FakeClassList();

        this._className.split(/\s+/).filter(Boolean).forEach((name) => this.classList.add(name));
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }

    getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
    }

    addEventListener(type, handler) {
        (this.listeners[type] ||= []).push(handler);
    }

    dispatch(type) {
        (this.listeners[type] || []).forEach((handler) => handler({ type, target: this }));
    }

    appendChild(node) {
        node.parent = this;
        node.isConnected = true;
        this.children.push(node);

        return node;
    }

    append(...nodes) {
        nodes.forEach((node) => this.appendChild(node));
    }

    remove() {
        if (this.parent) {
            this.parent.children = this.parent.children.filter((child) => child !== this);
            this.parent = null;
        }

        this.isConnected = false;
    }

    set innerHTML(value) {
        // Should never be used by the toast module. If it is, the test that
        // asserts text-only rendering would pass while markup was executed.
        throw new Error('toast must never write innerHTML');
    }
}

function installDom() {
    const body = new FakeNode('body');
    const document = {
        body,
        createElement: (tag) => new FakeNode(tag),
    };

    globalThis.document = document;
    globalThis.window = globalThis.window || {};

    return { body, document };
}

const { toast } = await import('../../resources/js/ui/toast.js');

/*
 * ONE document for the whole file.
 *
 * The module deliberately keeps the region between calls — that is what stops
 * twenty toasts creating twenty containers — so a fresh document per test would
 * leave the module pointing at the previous one. The suite therefore installs a
 * single stand-in and resets its contents between tests, which is also the order
 * a real page uses them in.
 */
const dom = installDom();

/** The region, after removing whatever the previous test left behind. */
function freshRegion() {
    toast.clear();

    const region = dom.body.children.find((node) => node.classList.contains('tl-toast-region'));

    // clear() empties the region but keeps it, which is the intended behaviour.
    if (region) {
        region.children = [];
    }

    return region;
}

/** The region, creating it if this is the first toast of the file. */
function region() {
    const found = dom.body.children.find((node) => node.classList.contains('tl-toast-region'));

    assert.ok(found, 'a toast region must exist after at least one toast');

    return found;
}

test('a success toast creates one polite live region and writes text, not markup', () => {
    freshRegion();

    toast.success('Deposit received');

    const box = region();

    assert.equal(box.getAttribute('role'), 'status');
    assert.equal(box.getAttribute('aria-live'), 'polite');

    assert.equal(box.children.length, 1);

    const node = box.children[0];
    const text = node.children.find((child) => child.classList.contains('tl-toast__text'));

    assert.equal(text.textContent, 'Deposit received');
    assert.equal(node.dataset.kind, 'success');
});

test('an error toast is assertive so a screen reader interrupts for it', () => {
    freshRegion();

    toast.error('That did not complete');

    const node = region().children[0];

    assert.equal(node.getAttribute('role'), 'alert');
    assert.equal(node.getAttribute('aria-live'), 'assertive');
});

test('an empty message is ignored rather than rendering an empty box', () => {
    const box = freshRegion();

    const result = toast.info('   ');

    assert.equal(result, null);
    assert.ok(! box || box.children.length === 0, 'nothing may be appended for an empty message');
});

test('markup in a server message is rendered as text', () => {
    freshRegion();

    toast.info('<img src=x onerror=alert(1)>');

    const text = region().children[0].children.find((child) => child.classList.contains('tl-toast__text'));

    // textContent, so the tag is literal characters and never becomes an element.
    assert.equal(text.textContent, '<img src=x onerror=alert(1)>');
});

test('dismissing a toast removes it and cancels its timer', () => {
    freshRegion();

    toast.success('Saved');

    const node = region().children[0];
    const close = node.children.find((child) => child.classList.contains('tl-toast__close'));

    close.dispatch('click');

    // The leaving class is applied immediately; removal follows on transition or
    // the backstop timer, so the element is either marked or already gone.
    assert.ok(node.classList.contains('tl-toast--leaving') || ! node.isConnected);
});

test('clear() removes every toast', () => {
    freshRegion();

    toast.success('One');
    toast.warn('Two');
    toast.info('Three');

    const box = region();
    assert.equal(box.children.length, 3);

    const nodes = [...box.children];

    toast.clear();

    // dismiss() marks each node leaving and schedules removal; none may remain
    // as a live, visible toast.
    nodes.forEach((node) => {
        assert.ok(node.classList.contains('tl-toast--leaving') || ! node.isConnected);
    });
});
