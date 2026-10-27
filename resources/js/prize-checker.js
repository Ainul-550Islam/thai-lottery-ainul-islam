/**
 * Prize checker for the public results page.
 *
 * ============================================================================
 * WHAT THIS MODULE IS ALLOWED TO SAY
 * ============================================================================
 * It posts six digits to `api.v1.glo.prize-check` and renders what comes back.
 * That is the whole of it. It does not compute a tier, does not apply a
 * multiplier, does not read a prize amount from the DOM, and does not decide
 * whether a ticket wins. Every figure on screen after a check came from the
 * server and is written with `setText`, which cannot execute markup.
 *
 * A checker is the one place a player is most inclined to believe a number, so
 * the rule is absolute: if the server did not send it, it is not printed.
 *
 * ============================================================================
 * ABSENCES ARE ANSWERS, NOT ERRORS
 * ============================================================================
 * "No result has been published yet" is a normal state, not a failure, and it is
 * rendered as a calm notice rather than a red box. Four outcomes are kept
 * visibly distinct because each asks the player to do something different:
 *
 *   NOT_CONFIGURED   wait — nothing is published yet
 *   UNAVAILABLE      the source cannot be published; contact support
 *   INVALID_TICKET   correct the digits (422)
 *   transport/5xx    retry; no claim about the ticket is made either way
 */
import {
    announce,
    apiRequest,
    errorCode,
    isNotConfigured,
    readL10n,
    serverMessage,
    setText,
    text,
} from './frontend-api.js';
import { toast } from './ui/toast.js';

/** Server states, mirrored from App\Services\Lottery\PrizeCheckService. */
const STATE_CHECKED = 'CHECKED';
const STATE_NOT_CONFIGURED = 'NOT_CONFIGURED';
const STATE_UNAVAILABLE = 'UNAVAILABLE';

const VERDICT_WINNING = 'WINNING';

/**
 * The dictionary this module needs. Every key has an English default so a page
 * that failed to render its JSON block still says something true rather than
 * rendering an empty box.
 */
const FALLBACK = {
    endpoint: '',
    checking: 'Checking…',
    invalid: 'Enter exactly six digits.',
    not_published: 'The latest draw has no published result, so no check can be made.',
    unavailable: 'The result source cannot be published right now. No check was made.',
    winning: 'This ticket matches a published prize tier.',
    not_winning: 'This ticket does not match any published prize tier.',
    checked_failed: 'The check could not be completed. Nothing was decided about this ticket.',
    draw_number: 'Draw number',
    published_at: 'Published',
    provenance: 'Source',
    gross: 'Gross',
    net: 'Net of stamp duty',
    stamp_duty: 'Stamp duty',
    claim_by: 'Claim by',
};

/**
 * Read one numeric-or-text field from the result panel.
 *
 * @param {HTMLElement} panel
 * @param {string} name the data attribute suffix, e.g. 'gross' -> [data-prize-gross]
 */
function field(panel, name) {
    return panel.querySelector(`[data-prize-${name}]`);
}

/**
 * Write into a named field, silently ignoring a field the template omits. A
 * missing node is not an error: it means this page does not show that value.
 */
function put(panel, name, value) {
    setText(field(panel, name), value);
}

/**
 * Wire one checker form.
 *
 * @param {HTMLElement} root the section owning the form and the result panel
 */
export function initPrizeChecker(root) {
    if (!root || root.dataset.prizeCheckerReady === '1') {
        return;
    }

    const form = root.querySelector('[data-prize-form]');
    const input = root.querySelector('[data-prize-input]');
    const submit = root.querySelector('[data-prize-submit]');
    const panel = root.querySelector('[data-prize-result]');
    const status = root.querySelector('[data-prize-status]');

    if (!form || !input || !submit || !panel) {
        return;
    }

    root.dataset.prizeCheckerReady = '1';

    const l10n = readL10n('prize-checker-l10n', FALLBACK);
    const endpoint = l10n.endpoint || root.dataset.endpoint || '';

    if (endpoint === '') {
        // Without an endpoint there is nothing honest to do. Saying so once, in
        // the panel, is better than a button that silently does nothing.
        panel.hidden = false;
        panel.dataset.state = STATE_NOT_CONFIGURED;
        put(panel, 'message', l10n.not_published);

        return;
    }

    /**
     * Render a terminal state that carries no money.
     */
    function renderAbsence(state, message) {
        panel.hidden = false;
        panel.dataset.state = state;
        panel.dataset.verdict = '';
        put(panel, 'message', message);

        const tier = panel.querySelector('[data-prize-tier]');
        const claim = panel.querySelector('[data-prize-claim]');

        if (tier) {
            tier.hidden = true;
        }

        if (claim) {
            claim.hidden = true;
        }
    }

    /**
     * Render a checked answer.
     *
     * @param {{state: string, verdict: string|null, tier: object|null, draw: object|null,
     *          provenance: object|null, claim_window: object|null, currency: string|null}} data
     */
    function renderChecked(data) {
        const verdict = data.verdict === null || data.verdict === undefined ? '' : String(data.verdict);
        const winning = verdict === VERDICT_WINNING;

        panel.hidden = false;
        panel.dataset.state = STATE_CHECKED;
        panel.dataset.verdict = verdict;

        put(panel, 'message', winning ? l10n.winning : l10n.not_winning);
        put(panel, 'draw-number', (data.draw && data.draw.draw_number) || '—');
        put(panel, 'published-at', (data.draw && data.draw.published_at) || '—');

        // The provenance label arrives as a translation KEY, never a sentence,
        // so both languages render from the same server payload.
        const labelKey = data.provenance && data.provenance.label_key
            ? String(data.provenance.label_key)
            : '';
        put(panel, 'provenance', labelKey ? text(l10n, labelKey) || '' : '');

        const tierBox = panel.querySelector('[data-prize-tier]');

        if (tierBox) {
            const tier = data.tier;

            if (winning && tier && typeof tier === 'object') {
                tierBox.hidden = false;
                put(panel, 'tier-label', text(l10n, String(tier.label_key || '')) || String(tier.key || ''));
                put(panel, 'gross', tier.amount || '—');
                put(panel, 'stamp-duty', tier.stamp_duty || '—');
                put(panel, 'net', tier.net || '—');
                put(panel, 'currency', data.currency || '');
            } else {
                tierBox.hidden = true;
            }
        }

        const claim = panel.querySelector('[data-prize-claim]');
        const claimBy = data.claim_window ? data.claim_window.claim_by : null;

        if (claim) {
            claim.hidden = !claimBy;
            put(panel, 'claim-by', claimBy || '');
        }
    }

    /**
     * Client-side digit check. It exists ONLY to avoid a pointless round trip;
     * the server re-validates and remains the authority, so a client that lets a
     * seven-digit value through still cannot obtain a result.
     */
    function digitsOnly(value) {
        const cleaned = String(value || '').replace(/\D+/g, '').slice(0, 6);

        return /^\d{6}$/.test(cleaned) ? cleaned : null;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const ticket = digitsOnly(input.value);

        if (ticket === null) {
            input.setAttribute('aria-invalid', 'true');
            announce(status, l10n.invalid);
            input.focus();

            return;
        }

        input.removeAttribute('aria-invalid');
        setText(status, '');

        const originalLabel = submit.textContent;
        submit.disabled = true;
        submit.textContent = l10n.checking;

        try {
            const response = await apiRequest(endpoint, {
                method: 'POST',
                body: { number: ticket },
            });

            if (!response.ok) {
                if (isNotConfigured(response)) {
                    renderAbsence(STATE_NOT_CONFIGURED, l10n.not_published);

                    return;
                }

                if (response.status === 422 || errorCode(response) === 'VALIDATION_FAILED') {
                    renderAbsence('INVALID_TICKET', serverMessage(response) || l10n.invalid);
                    input.setAttribute('aria-invalid', 'true');

                    return;
                }

                // No verdict is implied on a transport or server failure.
                const message = serverMessage(response) || l10n.checked_failed;
                renderAbsence('ERROR', message);
                toast.error(message);

                return;
            }

            const data = response.data && response.data.data ? response.data.data : null;

            if (!data || typeof data !== 'object' || !data.state) {
                renderAbsence('ERROR', l10n.checked_failed);

                return;
            }

            if (data.state === STATE_NOT_CONFIGURED) {
                renderAbsence(STATE_NOT_CONFIGURED, l10n.not_published);

                return;
            }

            if (data.state === STATE_UNAVAILABLE) {
                renderAbsence(STATE_UNAVAILABLE, l10n.unavailable);

                return;
            }

            if (data.state !== STATE_CHECKED) {
                renderAbsence('ERROR', l10n.checked_failed);

                return;
            }

            renderChecked(data);
            announce(status, field(panel, 'message')?.textContent || '');
        } catch (error) {
            renderAbsence('ERROR', l10n.checked_failed);
        } finally {
            submit.disabled = false;
            submit.textContent = originalLabel;
        }
    });

    // Stripping non-digits as they are typed keeps a leading zero (the reason a
    // ticket field is text and not number) and makes a pasted "042 042" work.
    input.addEventListener('input', () => {
        const cleaned = String(input.value || '').replace(/\D+/g, '').slice(0, 6);

        if (cleaned !== input.value) {
            input.value = cleaned;
        }
    });
}

/**
 * Boot every checker on the page.
 */
export function bootPrizeCheckers() {
    document.querySelectorAll('[data-prize-checker]').forEach((node) => {
        if (node instanceof HTMLElement) {
            initPrizeChecker(node);
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootPrizeCheckers, { once: true });
} else {
    bootPrizeCheckers();
}
