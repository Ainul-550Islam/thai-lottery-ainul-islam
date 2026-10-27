/**
 * Inline field errors, replacing `alert()` for input validation.
 *
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * A blocking alert for "please enter a valid amount" has four problems on a
 * real-money form:
 *
 *   1. it is modal — the member must dismiss a dialog before they can correct
 *      the field the dialog is about;
 *   2. it is unlabelled — a screen reader announces the browser frame, not the
 *      field, so the association between the message and the input is lost;
 *   3. it is not associated with the input programmatically, so assistive tech
 *      cannot re-read it after the field is fixed;
 *   4. it is the same modality for "your input is wrong" and "the server refused
 *      your money" — two very different situations.
 *
 * This module writes the message next to the field it is about, links it with
 * `aria-describedby`, sets `aria-invalid`, moves focus to the field, and returns
 * the element so a caller can clear it later.
 *
 * The message text is always supplied by the caller, and callers pass a string
 * they own or a server message — never a figure computed here.
 */

let sequence = 0;

/**
 * Locate (or create) the error container for a field.
 *
 * A page may already provide one: `[data-field-error="<name>"]` or an element
 * with `id="<inputId>-error"`. Reusing the page's own node keeps the layout and
 * its styling; creating one is the fallback so a bare input still gets a message
 * instead of silently doing nothing.
 *
 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} input
 * @returns {HTMLElement}
 */
function errorNodeFor(input) {
    const name = input.getAttribute('name') || '';
    const byName = name ? document.querySelector(`[data-field-error="${name}"]`) : null;

    if (byName) {
        return byName;
    }

    if (input.id) {
        const byId = document.querySelector(`[id="${input.id}-error"]`);

        if (byId) {
            return byId;
        }
    }

    let node = input.parentElement ? input.parentElement.querySelector('[data-field-error-generated]') : null;

    if (!node) {
        node = document.createElement('p');
        node.setAttribute('data-field-error-generated', '');
        node.className = 'mt-1.5 text-xs font-semibold text-rose-400';
        node.setAttribute('role', 'alert');
        sequence += 1;
        node.id = `field-error-${sequence}`;

        if (input.parentElement) {
            input.parentElement.appendChild(node);
        }
    }

    return node;
}

/**
 * Show a validation message against a field.
 *
 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement|null} input
 * @param {string} message
 * @returns {HTMLElement|null} the message element, so a caller can clear it
 */
export function showFieldError(input, message) {
    if (! input) {
        return null;
    }

    const node = errorNodeFor(input);
    node.textContent = String(message || '');

    if (node.id) {
        const described = (input.getAttribute('aria-describedby') || '')
            .split(/\s+/)
            .filter((token) => token !== '' && token !== node.id);

        described.push(node.id);
        input.setAttribute('aria-describedby', described.join(' '));
    }

    input.setAttribute('aria-invalid', 'true');

    // Focus, but never scroll the page out from under the member: the field is
    // already on screen in every case where this fires.
    if (typeof input.focus === 'function') {
        input.focus({ preventScroll: true });
    }

    return node;
}

/**
 * Clear the message for a field, and with it the invalid state.
 *
 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement|null} input
 */
export function clearFieldError(input) {
    if (! input) {
        return;
    }

    input.removeAttribute('aria-invalid');

    const name = input.getAttribute('name') || '';
    const candidates = [
        name ? document.querySelector(`[data-field-error="${name}"]`) : null,
        input.id ? document.querySelector(`[id="${input.id}-error"]`) : null,
        input.parentElement ? input.parentElement.querySelector('[data-field-error-generated]') : null,
    ];

    for (const node of candidates) {
        if (! node) {
            continue;
        }

        node.textContent = '';

        if (node.id) {
            const described = (input.getAttribute('aria-describedby') || '')
                .split(/\s+/)
                .filter((token) => token !== '' && token !== node.id);

            if (described.length === 0) {
                input.removeAttribute('aria-describedby');
            } else {
                input.setAttribute('aria-describedby', described.join(' '));
            }
        }
    }
}

/**
 * Bind "as soon as they fix it, the complaint goes away" behaviour.
 *
 * Without this the message outlives the mistake, which trains members to ignore
 * it. The first keystroke is enough: the field is re-validated on submit anyway.
 *
 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement|null} input
 */
export function clearOnEdit(input) {
    if (! input || input.dataset.fieldErrorBound === '1') {
        return;
    }

    input.dataset.fieldErrorBound = '1';
    input.addEventListener('input', () => clearFieldError(input));
}

export default { showFieldError, clearFieldError, clearOnEdit };
