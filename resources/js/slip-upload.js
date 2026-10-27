/**
 * Bank-transfer slip verification lane (Batch 08).
 *
 * Two independent merged backends serve this tab:
 *   1. POST /api/v1/deposit/bank-transfer/intent publishes the bank details and
 *      the unique reference a transfer must quote.
 *   2. POST /api/v1/deposit/slip-verification accepts EITHER a slip image OR a
 *      pasted QR payload, and GET .../slip-verification/{id} reports the
 *      eventual verification state.
 *
 * Each backend owns its own honest state. When the intent answers 501/503
 * NOT_CONFIGURED the bank-details block shows the localized notice and hides
 * the instructions; it does not invent an account. A full account number is
 * never rendered: only its last four characters are shown, like the backend's
 * own mask. The slip form stays usable because its verification backend is a
 * separate, authoritative lane; if that lane answers NOT_CONFIGURED the form
 * is disabled and the notice stays on screen.
 */

import {
    apiRequest,
    announce,
    errorCode,
    fillUrl,
    formatAmount,
    isNotConfigured,
    readL10n,
    serverMessage,
    setText,
    text,
} from './frontend-api.js';

const LANES_READY_EVENT = 'deposit:lanes-ready';
const TERMINAL_STATUSES = Object.freeze(['credited', 'needs_review', 'rejected', 'duplicate', 'expired']);
const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const ALLOWED_IMAGE_TYPES = Object.freeze(['image/jpeg', 'image/png', 'image/jpg']);
const MAX_POLLS = 24;

const FALLBACK = Object.freeze({
    notConfigured: 'Bank-transfer slip verification is not available yet.',
    notConfiguredHelp: 'The operator has not configured this payment lane. Nothing was submitted and no deposit was credited.',
    intentNotConfigured: 'Bank transfer instructions are not configured for this environment, so no account details are shown.',
    intentLoading: 'Loading bank transfer instructions…',
    intentFailed: 'The bank transfer instructions could not be loaded.',
    authenticationRequired: 'Sign in to submit a slip for verification.',
    networkError: 'The request could not be completed. Check your connection and try again.',
    serviceUnavailable: 'The slip verification service is temporarily unavailable. Nothing was credited.',
    validationError: 'Check the amount and the slip input, then try again.',
    imageLabel: 'Upload a slip image',
    qrLabel: 'Paste the slip QR payload',
    imageInvalidType: 'Use a JPG or PNG image of the slip.',
    imageTooLarge: 'The slip image must be 5 MB or smaller.',
    qrInvalid: 'Paste the QR payload from the slip (1000 characters maximum).',
    previewAlt: 'Selected bank slip preview',
    submitting: 'Submitting slip…',
    submit: 'Submit slip',
    statusLabel: 'Verification status',
    status_pending: 'Pending verification',
    status_verified: 'Verified — credit pending',
    status_credited: 'Credited',
    status_needs_review: 'Needs review',
    status_rejected: 'Rejected',
    status_duplicate: 'Duplicate slip',
    status_expired: 'Expired',
    reasonPrefix: 'Reason',
    reason_unknown: 'No further reason was published.',
    reason_upload_scan_not_clean: 'The uploaded file did not pass the safety scan.',
    reason_quarantined_file_unavailable: 'The uploaded file could not be read safely.',
    reason_provider_unreachable: 'The verification provider could not be reached.',
    reason_provider_not_configured: 'The verification provider is not configured.',
    reason_provider_rejected_slip: 'The provider rejected this slip.',
    reason_provider_error: 'The provider returned an error.',
    reason_provider_invalid: 'The provider could not read this slip.',
    reason_duplicate: 'This slip has already been used.',
    reason_provider_duplicate: 'This slip was already processed by the provider.',
    reason_trans_ref_already_seen: 'This transaction reference has already been used.',
    reason_amount_mismatch: 'The amount does not match the published deposit amount.',
    reason_provider_amount_invalid: 'The provider could not validate the amount.',
    reason_currency_mismatch: 'The currency does not match the published deposit.',
    reason_receiver_mismatch: 'The receiving account does not match the published account.',
    reason_expired: 'The slip has expired.',
    reason_slip_expired: 'The slip has expired.',
    reason_future_dated: 'The slip is dated in the future.',
    reason_fraud_denied: 'The slip was refused by account risk controls.',
    reason_deposit_mismatch: 'No matching deposit request was found.',
    reason_fraud_check_unavailable: 'The risk check could not be completed.',
    reason_manual_review_required: 'A manual review is required before any credit.',
    reason_matching_pending_deposit_not_found: 'No matching pending deposit was found.',
    reason_automatic_credit_pending: 'The automatic credit is still pending.',
    reason_deposit_completion_failed: 'The deposit could not be completed.',
    error_slip_verification_not_configured: 'Slip verification is not configured in this environment.',
    error_slip_invalid: 'That slip input could not be verified safely.',
    error_slip_credit_unconfirmed: 'The verified slip requires manual review before any further action.',
    error_slip_verification_failed: 'The slip could not be processed safely.',
    error_deposit_not_found: 'No matching deposit request was found for that reference.',
    error_slip_verification_not_found: 'That slip verification could not be found.',
    pollingHint: 'The status is still pending; the page checks again automatically.',
});

/** Mask an account number the way the backend does: only the last four glyphs. */
export function maskAccount(value) {
    const raw = String(value || '').replace(/\s+/g, '');
    if (raw.length <= 4) {
        return '••••';
    }
    return '•••• ' + raw.slice(-4);
}

function statusText(l10n, status) {
    const key = 'status_' + String(status || 'pending').toLowerCase();
    return Object.prototype.hasOwnProperty.call(l10n, key) ? l10n[key] : String(status || l10n.status_pending);
}

function reasonText(l10n, reason) {
    if (!reason) {
        return '';
    }
    const key = 'reason_' + String(reason).replace(/[^a-z0-9_]/g, '_');
    return Object.prototype.hasOwnProperty.call(l10n, key) ? l10n[key] : l10n.reason_unknown;
}

function init() {
    const root = document.getElementById('slip-upload');
    if (!root) {
        return;
    }

    const l10n = readL10n('slip-l10n', FALLBACK);
    const intentUrl = root.dataset.intentUrl || '';
    const verifyUrl = root.dataset.verifyUrl || '';
    const showTemplate = root.dataset.showUrl || '';
    const pollInterval = Math.max(3000, parseInt(root.dataset.pollIntervalMs || '5000', 10) || 5000);
    const currency = root.dataset.currency || 'THB';

    const bankBlock = root.querySelector('[data-slip-bank-details]');
    const bankNotice = root.querySelector('[data-slip-bank-notice]');
    const notice = root.querySelector('[data-lane-notice]');
    const status = root.querySelector('[data-slip-status]');
    const result = root.querySelector('[data-slip-result]');
    const form = root.querySelector('[data-lane-form]');
    const amountInput = root.querySelector('[data-slip-amount]');
    const referenceInput = root.querySelector('[data-slip-deposit-reference]');
    const imageInput = root.querySelector('[data-slip-image]');
    const qrInput = root.querySelector('[data-slip-qr-payload]');
    const imageMode = root.querySelector('[data-slip-mode="image"]');
    const qrMode = root.querySelector('[data-slip-mode="qr"]');
    const imageField = root.querySelector('[data-slip-image-field]');
    const qrField = root.querySelector('[data-slip-qr-field]');
    const preview = root.querySelector('[data-slip-preview]');
    const submit = root.querySelector('[data-slip-submit]');

    let mode = 'image';
    let previewUrl = '';
    let pollTimer = null;
    let polls = 0;
    let disposed = false;
    let laneDisabled = false;

    function clearPreview() {
        if (previewUrl !== '') {
            URL.revokeObjectURL(previewUrl);
            previewUrl = '';
        }
        if (preview) {
            preview.removeAttribute('src');
            preview.hidden = true;
        }
    }

    function setMode(next) {
        mode = next === 'qr' ? 'qr' : 'image';
        if (imageMode) imageMode.checked = mode === 'image';
        if (qrMode) qrMode.checked = mode === 'qr';
        if (imageField) imageField.hidden = mode !== 'image';
        if (qrField) qrField.hidden = mode !== 'qr';
        if (imageInput) {
            imageInput.disabled = mode !== 'image' || laneDisabled;
            imageInput.required = mode === 'image';
            imageInput.value = '';
        }
        if (qrInput) {
            qrInput.disabled = mode !== 'qr' || laneDisabled;
            qrInput.required = mode === 'qr';
            qrInput.value = '';
        }
        clearPreview();
    }

    function stopPolling() {
        if (pollTimer !== null) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function disableLane(message) {
        laneDisabled = true;
        stopPolling();
        if (form) {
            form.hidden = true;
        }
        announce(notice, message);
    }

    function renderVerification(data) {
        const state = String(data.status || 'pending').toLowerCase();
        const reason = data.reason_code || '';
        const label = root.querySelector('[data-slip-status-label]');
        const reasonNode = root.querySelector('[data-slip-reason]');
        const amountNode = root.querySelector('[data-slip-amount-display]');

        if (result) {
            result.hidden = false;
        }
        if (label) {
            setText(label, statusText(l10n, state));
            label.dataset.status = state;
        }
        if (amountNode) {
            setText(amountNode, formatAmount(data.amount, data.currency || currency));
        }
        if (reasonNode) {
            setText(reasonNode, reason ? text(l10n, 'reasonPrefix') + ': ' + reasonText(l10n, reason) : '');
            reasonNode.hidden = reason === '';
        }

        return state;
    }

    async function pollVerification(id) {
        if (!id || disposed || showTemplate === '') {
            return;
        }

        polls += 1;
        if (polls > MAX_POLLS) {
            stopPolling();
            announce(status, text(l10n, 'pollingHint'));
            return;
        }

        const url = fillUrl(showTemplate, '__VERIFICATION_ID__', id)
            .replace(':verification', encodeURIComponent(String(id)))
            .replace('{verification}', encodeURIComponent(String(id)));
        const response = await apiRequest(url, { method: 'GET' });

        if (disposed) {
            return;
        }
        if (!response.ok || !response.data || response.data.success !== true) {
            return;
        }

        const state = renderVerification(response.data.data || {});
        if (TERMINAL_STATUSES.includes(state)) {
            stopPolling();
        }
    }

    function followVerification(id, state) {
        stopPolling();
        polls = 0;
        if (TERMINAL_STATUSES.includes(state) || showTemplate === '') {
            return;
        }
        pollTimer = setInterval(() => pollVerification(id), pollInterval);
    }

    async function loadIntent() {
        if (intentUrl === '') {
            announce(bankNotice, text(l10n, 'intentFailed'));
            return;
        }

        const response = await apiRequest(intentUrl, {
            method: 'POST',
            body: { currency },
        });

        if (isNotConfigured(response) || (response.data && response.data.state === 'NOT_CONFIGURED')) {
            if (bankBlock) {
                bankBlock.hidden = true;
            }
            announce(bankNotice, text(l10n, 'intentNotConfigured'));
            return;
        }

        if (!response.ok || !response.data || response.data.success !== true) {
            announce(bankNotice, text(l10n, response.status === 0 ? 'networkError' : 'intentFailed'));
            return;
        }

        const data = response.data.data || response.data;
        const instructions = data.instructions || data;
        const map = {
            '[data-slip-bank-name]': instructions.bank_name,
            '[data-slip-account-name]': instructions.account_name,
            '[data-slip-account-number]': instructions.account_number ? maskAccount(instructions.account_number) : '',
            '[data-slip-reference]': instructions.reference,
            '[data-slip-instructions]': instructions.instructions,
        };

        Object.keys(map).forEach((selector) => {
            const node = root.querySelector(selector);
            if (!node) {
                return;
            }
            setText(node, map[selector] || '');
            const wrapper = node.closest('[data-slip-detail]');
            if (wrapper) {
                wrapper.hidden = !map[selector];
            }
        });

        if (bankBlock) {
            bankBlock.hidden = false;
        }
        if (bankNotice) {
            bankNotice.hidden = true;
            setText(bankNotice, '');
        }
        if (referenceInput && instructions.reference && !referenceInput.value) {
            referenceInput.value = String(instructions.reference);
        }
    }

    function validateImage(file) {
        if (!file) {
            return text(l10n, 'imageInvalidType');
        }
        if (!ALLOWED_IMAGE_TYPES.includes(String(file.type).toLowerCase())) {
            return text(l10n, 'imageInvalidType');
        }
        if (file.size > MAX_IMAGE_BYTES) {
            return text(l10n, 'imageTooLarge');
        }
        return '';
    }

    async function submitSlip(event) {
        event.preventDefault();
        if (laneDisabled) {
            return;
        }
        stopPolling();

        const amount = amountInput ? String(amountInput.value || '').trim() : '';
        if (!/^[0-9]{1,12}(?:\.[0-9]{1,2})?$/.test(amount) || Number(amount) <= 0) {
            announce(status, text(l10n, 'validationError'));
            return;
        }

        const payload = new FormData();
        payload.append('amount', amount);
        const reference = referenceInput ? String(referenceInput.value || '').trim() : '';
        if (reference !== '') {
            payload.append('deposit_reference', reference);
        }

        if (mode === 'image') {
            const file = imageInput && imageInput.files ? imageInput.files[0] : null;
            const error = validateImage(file);
            if (error !== '') {
                announce(status, error);
                return;
            }
            payload.append('slip_image', file, file.name);
        } else {
            const qr = qrInput ? String(qrInput.value || '').trim() : '';
            if (qr === '' || qr.length > 1000) {
                announce(status, text(l10n, 'qrInvalid'));
                return;
            }
            payload.append('qr_payload', qr);
        }

        if (submit) {
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            setText(submit, text(l10n, 'submitting'));
        }
        announce(status, text(l10n, 'submitting'));

        const response = await apiRequest(verifyUrl, { method: 'POST', body: payload });

        if (submit) {
            submit.disabled = false;
            submit.removeAttribute('aria-busy');
            setText(submit, text(l10n, 'submit'));
        }

        const code = errorCode(response);
        if (isNotConfigured(response) || (response.data && response.data.state === 'NOT_CONFIGURED') || code === 'slip_verification_not_configured') {
            disableLane(text(l10n, 'notConfigured') + ' ' + text(l10n, 'notConfiguredHelp'));
            return;
        }

        if (response.status === 401) {
            announce(status, text(l10n, 'authenticationRequired'));
            return;
        }

        if (!response.ok || !response.data || response.data.success !== true) {
            const key = 'error_' + (code || 'slip_verification_failed');
            const mapped = Object.prototype.hasOwnProperty.call(l10n, key)
                ? text(l10n, key)
                : (serverMessage(response) || text(l10n, 'error_slip_verification_failed'));
            announce(status, mapped);
            return;
        }

        const data = response.data.data || {};
        const state = renderVerification(data);
        announce(status, statusText(l10n, state));
        clearPreview();
        if (imageInput) imageInput.value = '';
        if (qrInput) qrInput.value = '';
        followVerification(data.verification_id, state);
    }

    document.addEventListener(LANES_READY_EVENT, (event) => {
        const states = (event && event.detail && event.detail.states) || {};
        if (states.bank_transfer === 'NOT_CONFIGURED') {
            disableLane(text(l10n, 'notConfigured') + ' ' + text(l10n, 'notConfiguredHelp'));
        }
    });

    const retained = window.depositLaneStates || {};
    if (root.dataset.laneState === 'NOT_CONFIGURED' || retained.bank_transfer === 'NOT_CONFIGURED') {
        disableLane(text(l10n, 'notConfigured') + ' ' + text(l10n, 'notConfiguredHelp'));
    }

    if (imageMode) imageMode.addEventListener('change', () => setMode('image'));
    if (qrMode) qrMode.addEventListener('change', () => setMode('qr'));

    if (imageInput) {
        imageInput.addEventListener('change', () => {
            clearPreview();
            const file = imageInput.files ? imageInput.files[0] : null;
            const error = validateImage(file);
            if (error !== '') {
                announce(status, error);
                imageInput.value = '';
                return;
            }
            if (preview && file) {
                previewUrl = URL.createObjectURL(file);
                preview.src = previewUrl;
                preview.hidden = false;
            }
            announce(status, '');
        });
    }

    if (form) {
        form.addEventListener('submit', submitSlip);
    }

    window.addEventListener('pagehide', () => {
        disposed = true;
        stopPolling();
        clearPreview();
    }, { once: true });

    setMode('image');
    loadIntent();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}

export default { init, maskAccount };
