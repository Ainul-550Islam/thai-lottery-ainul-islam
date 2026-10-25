/**
 * Public payout ILLUSTRATION controller (Batch 08).
 *
 * The retired simulator posted digit_input / stake_per_line and rendered
 * invented "generated lines". The merged backend exposes exactly one honest
 * calculation: POST /api/v1/public/how-to-play/simulate with a published bet
 * type and a stake, answering stake × the published multiplier — or
 * NOT_CONFIGURED for that bet type / for the whole lane. This module prints
 * only what the server returned and always prints the server's mandatory
 * disclaimer next to a READY figure.
 *
 * It never computes a multiplier, never predicts a number, never states a
 * probability and never promises winnings. A 501 (flag off), a 503 and a
 * per-bet-type NOT_CONFIGURED all produce a neutral localized message, and
 * the figure area is hidden rather than left showing a stale number.
 */

import {
    apiRequest,
    announce,
    formatAmount,
    isNotConfigured,
    readL10n,
    serverMessage,
    setText,
    text,
} from './frontend-api.js';

const FALLBACK = Object.freeze({
    flagOff: 'The payout illustration is not enabled on this site yet. The guide above still describes the platform journey.',
    betTypeNotConfigured: 'No single published payout rate exists for this bet type, so no illustration is shown.',
    serviceUnavailable: 'The illustration service could not be reached. No figure is shown.',
    networkError: 'The request could not be completed. Check your connection and try again.',
    validationError: 'Enter the stake as an amount with at most two decimal places.',
    submitting: 'Calculating illustration…',
    submit: 'Illustrate return',
    ready: 'Illustration available',
    notReady: 'Not configured',
    stakeLabel: 'Stake',
    multiplierLabel: 'Published payout rate',
    grossLabel: 'Return if matched (gross)',
    netLabel: 'Return if matched (net)',
    currencyLabel: 'Currency',
    basisSourceLabel: 'Payout rate source',
    netBasisLabel: 'Net basis',
    disclaimerLabel: 'Important',
    disclaimerFallback: 'Illustration only. It is not a prediction, not a probability and not a promise of winnings.',
});

function init() {
    const root = document.getElementById('payout-illustration');
    if (!root) {
        return;
    }

    const l10n = readL10n('how-to-play-l10n', FALLBACK);
    const form = root.querySelector('[data-illustration-form]');
    const select = root.querySelector('[data-illustration-bet-type]');
    const stake = root.querySelector('[data-illustration-stake]');
    const submit = root.querySelector('[data-illustration-submit]');
    const status = root.querySelector('[data-illustration-status]');
    const result = root.querySelector('[data-illustration-result]');
    const disclaimerNode = root.querySelector('[data-illustration-disclaimer]');
    const endpoint = root.dataset.simulateUrl || '';
    const locale = (document.documentElement.lang || 'en').split('-')[0].toLowerCase();
    let pending = false;

    function hideResult(message) {
        if (result) {
            result.hidden = true;
        }
        if (disclaimerNode) {
            setText(disclaimerNode, message || text(l10n, 'disclaimerFallback'));
        }
        announce(status, message || text(l10n, 'notReady'));
    }

    function render(data) {
        if (!result) {
            return;
        }

        setText(root.querySelector('[data-illustration-stake-value]'), formatAmount(data.stake, data.currency));
        setText(root.querySelector('[data-illustration-multiplier]'), String(data.multiplier || ''));
        setText(root.querySelector('[data-illustration-gross]'), formatAmount(data.gross_return_if_matched, data.currency));
        setText(root.querySelector('[data-illustration-net]'), formatAmount(data.net_if_matched, data.currency));
        setText(root.querySelector('[data-illustration-currency]'), String(data.currency || ''));

        const basis = data.basis || {};
        setText(root.querySelector('[data-illustration-source]'), String(basis.multiplier_source || ''));
        setText(root.querySelector('[data-illustration-net-basis]'), String(basis.net_basis || ''));
        setText(disclaimerNode, String(data.disclaimer || text(l10n, 'disclaimerFallback')));

        result.hidden = false;
        announce(status, text(l10n, 'ready'));
    }

    async function illustrate(event) {
        event.preventDefault();
        if (pending) {
            return;
        }

        const betType = select ? String(select.value || '').trim().toLowerCase() : '';
        const amount = stake ? String(stake.value || '').trim() : '';

        if (betType === '' || !/^\d{1,13}(?:\.\d{1,2})?$/.test(amount) || Number(amount) <= 0) {
            hideResult(text(l10n, 'validationError'));
            return;
        }

        pending = true;
        if (submit) {
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            setText(submit, text(l10n, 'submitting'));
        }
        hideResult('');

        const response = await apiRequest(endpoint, {
            method: 'POST',
            body: { bet_type: betType, stake: amount, locale },
        });

        pending = false;
        if (submit) {
            submit.disabled = false;
            submit.removeAttribute('aria-busy');
            setText(submit, text(l10n, 'submit'));
        }

        const data = response.data || {};
        const flagOff = response.status === 501 || (isNotConfigured(response) && data.state === 'NOT_CONFIGURED');

        if (flagOff) {
            hideResult(text(l10n, 'flagOff'));
            return;
        }

        if (response.status === 503 || (isNotConfigured(response) && !data.data)) {
            hideResult(text(l10n, 'serviceUnavailable'));
            return;
        }

        if (response.status === 422 || data.state === 'INVALID_INPUT') {
            hideResult(serverMessage(response) || text(l10n, 'validationError'));
            return;
        }

        if (!response.ok || data.success !== true) {
            hideResult(text(l10n, response.status === 0 ? 'networkError' : 'serviceUnavailable'));
            return;
        }

        const illustration = data.data || {};
        if (data.state === 'NOT_CONFIGURED' || illustration.state === 'NOT_CONFIGURED') {
            hideResult(String(illustration.reason || data.message || text(l10n, 'betTypeNotConfigured')));
            return;
        }

        if (illustration.state !== 'READY' || !illustration.multiplier || !illustration.gross_return_if_matched) {
            hideResult(text(l10n, 'betTypeNotConfigured'));
            return;
        }

        render(illustration);
    }

    if (form) {
        form.addEventListener('submit', illustrate);
    }

    if (select) {
        select.addEventListener('change', () => {
            hideResult('');
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}

export default { init };
