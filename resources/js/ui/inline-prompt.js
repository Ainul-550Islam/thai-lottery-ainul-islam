/**
 * Inline input, replacing window.prompt().
 *
 * ============================================================================
 * WHY prompt() HAD TO GO FROM A REAL-MONEY SETTINGS PAGE
 * ============================================================================
 * `prompt()` is modal, cannot be styled, cannot be labelled for a screen
 * reader, renders its default value in a text box the browser does not mark as
 * sensitive, and — the part that matters most here — it gives the caller no way
 * to show WHY an entry was refused. The user gets one shot: the dialog closes,
 * the value is gone, and the only feedback available is a second dialog.
 *
 * These call sites are security operations (a 2FA confirmation code, a limit
 * value, a break duration). A person typing a TOTP code into a browser modal has
 * no way to see the secret they are being asked about, and a mistyped code
 * destroys the whole flow.
 *
 * ============================================================================
 * WHAT THIS PROVIDES INSTEAD
 * ============================================================================
 * A form rendered next to the control that owns it:
 *
 *   - a real <label> bound to a real <input>, with inputmode/pattern so a mobile
 *     keyboard is appropriate and a numeric code cannot acquire letters;
 *   - helper text the caller supplies (the 2FA secret, the allowed range);
 *   - an inline error paragraph wired with aria-describedby and
 *     aria-invalid, so a refusal is announced and visible at once;
 *   - Escape and the Cancel button both resolve null — the caller decides what
 *     "cancelled" means, this module never assumes;
 *   - autocomplete="one-time-code" and autocomplete="off" are chosen by the
 *     caller rather than guessed, because a password manager filling a limit
 *     field is a nuisance and filling a TOTP field is a feature.
 *
 * Nothing is submitted from here. The caller gets the string and remains
 * responsible for the request, which is what keeps this module free of
 * knowledge about any endpoint.
 */

let sequence = 0;

/**
 * Show an inline prompt.
 *
 * @param {Object} options
 * @param {HTMLElement} options.target        element the prompt is inserted after
 * @param {string} options.label              visible label text
 * @param {string} [options.help]             helper sentence under the field
 * @param {string} [options.value]            initial value
 * @param {string} [options.submitLabel]      submit button text
 * @param {string} [options.cancelLabel]      cancel button text
 * @param {string} [options.inputmode]        'text' | 'numeric' | 'decimal'
 * @param {string} [options.pattern]          HTML pattern attribute
 * @param {string} [options.autocomplete]     'one-time-code' | 'off' | 'current-password'
 * @param {number} [options.maxlength]
 * @param {boolean} [options.secret]          render as type=password
 * @returns {Promise<string|null>}
 */
export function inlinePrompt(options) {
    const target = options && options.target;

    if (!target || typeof target.insertAdjacentElement !== 'function') {
        return Promise.resolve(null);
    }

    // One prompt at a time: a second request replaces the first, so two open
    // forms can never race to write the same setting.
    const existing = document.querySelector('[data-inline-prompt]');

    if (existing) {
        existing.remove();
    }

    sequence += 1;
    const id = `inline-prompt-${sequence}`;

    const wrapper = document.createElement('form');
    wrapper.className = 'tl-inline-prompt';
    wrapper.dataset.inlinePrompt = '1';
    wrapper.setAttribute('novalidate', 'novalidate');

    const label = document.createElement('label');
    label.className = 'tl-inline-prompt__label';
    label.setAttribute('for', id);
    label.textContent = String(options.label || '');

    const input = document.createElement('input');
    input.className = 'tl-inline-prompt__input';
    input.id = id;
    input.name = id;
    input.type = options.secret === true ? 'password' : 'text';
    input.value = options.value === undefined || options.value === null ? '' : String(options.value);

    if (options.inputmode) {
        input.setAttribute('inputmode', String(options.inputmode));
    }

    if (options.pattern) {
        input.setAttribute('pattern', String(options.pattern));
    }

    input.setAttribute('autocomplete', String(options.autocomplete || 'off'));
    input.spellcheck = false;

    if (typeof options.maxlength === 'number') {
        input.setAttribute('maxlength', String(options.maxlength));
    }

    const help = document.createElement('p');
    help.className = 'tl-inline-prompt__help';
    help.id = `${id}-help`;
    help.hidden = !options.help;
    help.textContent = String(options.help || '');

    const error = document.createElement('p');
    error.className = 'tl-inline-prompt__error';
    error.id = `${id}-error`;
    error.setAttribute('role', 'alert');
    error.hidden = true;

    input.setAttribute('aria-describedby', `${id}-help ${id}-error`);

    const actions = document.createElement('div');
    actions.className = 'tl-inline-prompt__actions';

    const submit = document.createElement('button');
    submit.type = 'submit';
    submit.className = 'tl-inline-prompt__submit';
    submit.textContent = String(options.submitLabel || 'Confirm');

    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'tl-inline-prompt__cancel';
    cancel.textContent = String(options.cancelLabel || 'Cancel');

    actions.append(submit, cancel);
    wrapper.append(label, input, help, error, actions);

    target.insertAdjacentElement('afterend', wrapper);

    return new Promise((resolve) => {
        let settled = false;

        /**
         * Resolve once. Every exit path — submit, cancel, Escape — calls this,
         * so no path can leave the promise pending and hang the caller's flow.
         */
        function finish(value) {
            if (settled) {
                return;
            }

            settled = true;
            wrapper.remove();
            resolve(value);
        }

        /**
         * Report a refusal without closing the form. Public on the returned
         * wrapper's dataset via the custom event below, so a caller can say
         * "that code was rejected" and let the user try again — which prompt()
         * could never do.
         */
        function fail(message) {
            error.textContent = String(message || '');
            error.hidden = !message;
            input.setAttribute('aria-invalid', 'true');
            input.focus();
        }

        wrapper.addEventListener('submit', (event) => {
            event.preventDefault();

            const value = String(input.value || '').trim();

            if (value === '') {
                fail(options.requiredMessage || 'A value is required.');
                return;
            }

            if (options.pattern) {
                let ok = true;

                try {
                    ok = new RegExp(`^(?:${options.pattern})$`).test(value);
                } catch (_) {
                    ok = true;
                }

                if (! ok) {
                    fail(options.invalidMessage || 'That value is not in the expected format.');
                    return;
                }
            }

            finish(value);
        });

        cancel.addEventListener('click', () => finish(null));

        wrapper.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                finish(null);
            }
        });

        // A caller that needs to reject a server response re-opens the flow by
        // calling inlinePrompt again; this listener lets it keep the SAME form
        // instead, so the typed value survives a round trip.
        wrapper.addEventListener('inline-prompt:error', (event) => {
            fail(event && event.detail ? event.detail.message : '');
        });

        input.focus();

        if (typeof input.select === 'function' && input.value !== '') {
            input.select();
        }
    });
}

export default inlinePrompt;
