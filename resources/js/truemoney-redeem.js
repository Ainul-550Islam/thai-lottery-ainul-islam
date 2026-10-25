/**
 * TrueMoney voucher redemption lane (Batch 08).
 *
 * The voucher URL is a bearer credential: it is read once, validated against
 * the same host/regex rule as the server, sent to the merged redemption
 * endpoint, and then removed from the form. It is NEVER rendered back into the
 * DOM, echoed in an error message, copied to a toast, or logged. The only
 * money figure shown is the amount the server says it credited.
 */

import {
    apiRequest,
    announce,
    errorCode,
    formatAmount,
    isNotConfigured,
    readL10n,
    serverMessage,
    setText,
    text,
} from './frontend-api.js';

const LANES_READY_EVENT = 'deposit:lanes-ready';

const FALLBACK = Object.freeze({
    notConfigured: 'TrueMoney voucher redemption is not available yet.',
    notConfiguredHelp: 'The operator has not configured this payment method. No voucher was submitted and nothing was credited.',
    authenticationRequired: 'Sign in to redeem a voucher.',
    networkError: 'The request could not be completed. Check your connection and try again.',
    serviceUnavailable: 'The voucher service is temporarily unavailable. Do not submit the same voucher again until support confirms.',
    validationError: 'That voucher link is not valid.',
    voucherUrlInvalid: 'Use the full https voucher link from your TrueMoney gift message.',
    redeeming: 'Checking voucher…',
    submit: 'Redeem voucher',
    creditedTitle: 'Voucher credited',
    creditedAmount: 'Credited amount',
    depositReference: 'Deposit reference',
    pendingReview: 'The voucher is being reconciled. Do not submit it again.',
    error_truemoney_not_configured: 'Voucher redemption is not configured in this environment.',
    error_truemoney_api_not_acknowledged: 'The voucher provider has not confirmed the redemption. Do not submit it again.',
    error_truemoney_voucher_invalid: 'That voucher link is not valid.',
    error_truemoney_voucher_already_processed: 'This voucher has already been submitted.',
    error_truemoney_daily_count_limit: 'The daily voucher limit for this account has been reached.',
    error_truemoney_daily_amount_limit: 'The daily voucher amount limit for this account has been reached.',
    error_truemoney_fraud_denied: 'The voucher was refused by account risk controls.',
    error_truemoney_outcome_unknown: 'The outcome of this voucher is unknown. Contact support instead of trying again.',
    error_truemoney_credit_unconfirmed: 'The credit is unconfirmed. Contact support instead of trying again.',
    error_truemoney_review_required: 'This voucher needs manual review. Do not submit it again.',
    error_truemoney_processing_failed: 'The voucher could not be processed safely.',
    error_unauthenticated: 'Sign in to redeem a voucher.',
    error_slip_verification_failed: 'The voucher could not be processed safely.',
});

/**
 * The client-side mirror of RedeemTruemoneyVoucherRequest: HTTPS only, the
 * configured host only, the campaign path only, and a 32-64 character token.
 * The host comes from the page (which reads config('truemoney.allowed_host')),
 * never from the voucher URL itself.
 *
 * @param {string} url
 * @param {string} host
 * @returns {boolean}
 */
export function isValidVoucherUrl(url, host) {
    const allowed = String(host || 'gift.truemoney.com').trim().toLowerCase();
    const value = String(url || '');

    if (value.length > 200 || allowed === '' || !/^[a-z0-9.-]+$/i.test(allowed)) {
        return false;
    }

    const escaped = allowed.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const pattern = new RegExp('^https://' + escaped + '/campaign/?\\?v=[A-Za-z0-9]{32,64}$');

    return pattern.test(value);
}

function init() {
    const root = document.getElementById('truemoney-redeem');
    if (!root) {
        return;
    }

    const l10n = readL10n('truemoney-l10n', FALLBACK);
    const form = root.querySelector('[data-lane-form]');
    const notice = root.querySelector('[data-lane-notice]');
    const status = root.querySelector('[data-truemoney-status]');
    const result = root.querySelector('[data-truemoney-result]');
    const input = root.querySelector('[data-truemoney-url]');
    const submit = root.querySelector('[data-truemoney-submit]');
    const endpoint = root.dataset.redeemUrl || '';
    const host = root.dataset.voucherHost || 'gift.truemoney.com';
    const currency = root.dataset.currency || 'THB';
    let pending = false;

    function disableLane(message) {
        if (form) {
            form.hidden = true;
        }
        if (input) {
            input.value = '';
            input.disabled = true;
        }
        announce(notice, message);
    }

    document.addEventListener(LANES_READY_EVENT, (event) => {
        const states = (event && event.detail && event.detail.states) || {};
        if (states.truemoney === 'NOT_CONFIGURED') {
            disableLane(text(l10n, 'notConfigured') + ' ' + text(l10n, 'notConfiguredHelp'));
        }
    });

    const retained = window.depositLaneStates || {};
    if (root.dataset.laneState === 'NOT_CONFIGURED' || retained.truemoney === 'NOT_CONFIGURED') {
        disableLane(text(l10n, 'notConfigured') + ' ' + text(l10n, 'notConfiguredHelp'));
    }

    if (!form) {
        return;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (pending) {
            return;
        }

        const voucherUrl = input ? String(input.value || '').trim() : '';

        if (!isValidVoucherUrl(voucherUrl, host)) {
            announce(status, text(l10n, 'voucherUrlInvalid'));
            if (input) {
                input.setAttribute('aria-invalid', 'true');
                input.focus();
            }
            return;
        }

        // The bearer credential leaves the DOM before any network activity.
        if (input) {
            input.value = '';
            input.removeAttribute('aria-invalid');
        }

        pending = true;
        if (submit) {
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            setText(submit, text(l10n, 'redeeming'));
        }
        if (result) {
            result.hidden = true;
        }
        announce(status, text(l10n, 'redeeming'));

        const response = await apiRequest(endpoint, {
            method: 'POST',
            body: { voucher_url: voucherUrl },
        });

        pending = false;
        if (submit) {
            submit.disabled = false;
            submit.removeAttribute('aria-busy');
            setText(submit, text(l10n, 'submit'));
        }

        const code = errorCode(response);
        const notConfigured = isNotConfigured(response)
            || (response.data && response.data.state === 'NOT_CONFIGURED')
            || code === 'truemoney_not_configured'
            || code === 'truemoney_api_not_acknowledged';

        if (notConfigured) {
            disableLane(text(l10n, 'notConfigured') + ' ' + text(l10n, 'notConfiguredHelp'));
            root.dataset.laneState = 'NOT_CONFIGURED';
            return;
        }

        if (response.status === 401) {
            announce(status, text(l10n, 'authenticationRequired'));
            return;
        }

        if (response.status === 422 && !code) {
            announce(status, serverMessage(response) || text(l10n, 'validationError'));
            return;
        }

        if (!response.ok || !response.data || response.data.success !== true) {
            const key = 'error_' + (code || 'truemoney_processing_failed');
            const mapped = Object.prototype.hasOwnProperty.call(l10n, key) ? text(l10n, key) : text(l10n, 'error_truemoney_processing_failed');
            announce(status, mapped);

            if (code === 'truemoney_outcome_unknown' || code === 'truemoney_credit_unconfirmed'
                || code === 'truemoney_review_required' || code === 'truemoney_voucher_already_processed') {
                announce(status, mapped + ' ' + text(l10n, 'pendingReview'));
            }
            return;
        }

        const data = response.data.data || {};
        const amount = formatAmount(data.amount, data.currency || currency);

        setText(root.querySelector('[data-truemoney-amount]'), amount);
        setText(root.querySelector('[data-truemoney-reference]'), String(data.deposit_reference || ''));
        if (result) {
            result.hidden = false;
        }
        announce(status, text(l10n, 'creditedTitle'));
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}

export default { init, isValidVoucherUrl };
