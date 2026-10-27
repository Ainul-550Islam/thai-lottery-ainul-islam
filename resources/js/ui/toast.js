/**
 * Shared toast notifications for the player and public portals.
 *
 * ============================================================================
 * WHY THIS EXISTS INSTEAD OF alert()
 * ============================================================================
 * `alert()` and `prompt()` were used in this codebase as a success signal. That
 * is wrong in three separate ways, and this module replaces all three:
 *
 *   1. alert() is MODAL AND BLOCKING. It stops the page's timers, its polling
 *      and its in-flight announcements, which is exactly when a portal is doing
 *      something the player is waiting on.
 *
 *   2. alert() is UNANNOUNCED NEARLY EVERYWHERE. It is not a live region, so a
 *      screen reader user frequently never hears it. A confirmation that half the
 *      users never receive is not a confirmation.
 *
 *   3. alert() LOOKS IDENTICAL FOR "SENT 500 THB" AND "COULD NOT REACH THE
 *      SERVER". The browser chrome is the same, the button is the same, and the
 *      text is free-form, so every caller invents its own wording and its own
 *      notion of severity. A toast carries an explicit, small vocabulary.
 *
 * ============================================================================
 * THE CONTRACT
 * ============================================================================
 *   toast.success(text)   an operation the SERVER confirmed completed
 *   toast.error(text)     an operation that did not complete
 *   toast.info(text)      a neutral statement (a limit is now in force, etc.)
 *   toast.warn(text)      something needs attention but nothing failed
 *
 * `text` is rendered with textContent, never innerHTML. Money amounts, draw
 * numbers and reference codes reach these functions from server responses; if a
 * server ever returns a string containing markup, this module must not execute
 * it.
 *
 * EVERY TOAST IS ANNOUNCED. The container is a polite live region; errors are
 * additionally assertive. The same text is therefore available to a sighted
 * user, a screen reader user, and to assistive tech that is watching the page.
 *
 * NO TIMERS OUTLIVE THE ELEMENT. A toast removes itself on a timer AND on
 * dismissal, and both paths cancel the other. Nothing here is left scheduled
 * after the element is gone, so a page that shows twenty toasts does not leak
 * twenty clocks.
 */

/** Milliseconds a toast stays on screen. Errors stay longer than confirmations. */
const DEFAULT_DURATION = 5000;
const ERROR_DURATION = 12000;

/** The single container element, created on first use. */
let container = null;

/** Live timers, keyed by element, so dismissal can cancel the expiry. */
const timers = new WeakMap();

/**
 * The toast container.
 *
 * One container per document, created lazily and reused. It is placed at the end
 * of <body> so it is not clipped by a transformed ancestor and does not shift
 * the layout of anything above it.
 */
function containerFor() {
    if (container && container.isConnected) {
        return container;
    }

    container = document.createElement('div');
    container.className = 'tl-toast-region';
    container.setAttribute('role', 'status');
    container.setAttribute('aria-live', 'polite');
    container.setAttribute('aria-relevant', 'additions');
    // Not aria-atomic: each toast announces itself rather than re-announcing the
    // whole stack every time another one arrives.
    document.body.appendChild(container);

    return container;
}

/**
 * Remove one toast, cancelling its pending expiry first.
 */
function dismiss(node) {
    if (!node || !node.isConnected) {
        return;
    }

    const timer = timers.get(node);

    if (timer) {
        clearTimeout(timer);
        timers.delete(node);
    }

    node.classList.add('tl-toast--leaving');

    // A CSS transition may or may not exist; remove on the next frame either way
    // so the element is gone even if the stylesheet failed to load.
    const remove = () => node.remove();

    if (typeof node.addEventListener === 'function') {
        node.addEventListener('transitionend', remove, { once: true });
        // Backstop: if no transition fires, remove anyway. This is what keeps a
        // missing stylesheet from leaving an invisible element in the DOM.
        setTimeout(remove, 400);
    } else {
        remove();
    }
}

/**
 * Build and show one toast.
 *
 * @param {string} kind   'success' | 'error' | 'info' | 'warn'
 * @param {string} text   already-localised message; rendered as text
 * @param {number} duration milliseconds on screen
 */
function show(kind, text, duration) {
    const message = typeof text === 'string' ? text.trim() : '';

    if (message === '') {
        // An empty message is a caller bug. Rendering an empty box would look
        // like a glitch to the user and tell a developer nothing, so the console
        // is the right place for it.
        if (typeof console !== 'undefined' && console.warn) {
            console.warn('toast: empty message ignored');
        }

        return null;
    }

    const region = containerFor();

    const node = document.createElement('div');
    node.className = `tl-toast tl-toast--${kind}`;
    node.dataset.kind = kind;

    // Errors interrupt; everything else waits its turn.
    if (kind === 'error') {
        node.setAttribute('role', 'alert');
        node.setAttribute('aria-live', 'assertive');
    }

    const body = document.createElement('p');
    body.className = 'tl-toast__text';
    body.textContent = message;

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'tl-toast__close';
    close.setAttribute('aria-label', 'Dismiss');
    close.textContent = '×';
    close.addEventListener('click', () => dismiss(node));

    node.append(body, close);
    region.appendChild(node);

    // Hovering or focusing pauses the expiry: a message that disappears while
    // the user is reading it (or tabbing to its dismiss button) is hostile.
    let remaining = typeof duration === 'number' && duration > 0
        ? duration
        : (kind === 'error' ? ERROR_DURATION : DEFAULT_DURATION);
    let startedAt = Date.now();
    let timer = setTimeout(expire, remaining);

    function expire() {
        dismiss(node);
    }

    function pause() {
        if (!timers.has(node)) {
            return;
        }

        clearTimeout(timer);
        timers.delete(node);
        remaining = Math.max(1200, remaining - (Date.now() - startedAt));
    }

    function resume() {
        if (timers.has(node)) {
            return;
        }

        startedAt = Date.now();
        timer = setTimeout(expire, remaining);
        timers.set(node, timer);
    }

    node.addEventListener('mouseenter', pause);
    node.addEventListener('focusin', pause);
    node.addEventListener('mouseleave', resume);
    node.addEventListener('focusout', resume);

    timers.set(node, timer);

    return node;
}

/**
 * Remove every toast. Used when the page navigates within itself or when a
 * portal tears down its state.
 */
function clear() {
    if (!container) {
        return;
    }

    Array.from(container.children).forEach(dismiss);
}

export const toast = {
    success: (text, duration) => show('success', text, duration),
    error: (text, duration) => show('error', text, duration),
    info: (text, duration) => show('info', text, duration),
    warn: (text, duration) => show('warn', text, duration),
    clear,
};

export default toast;
